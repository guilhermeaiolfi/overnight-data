<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use Closure;
use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Result\Parser\RootNode;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\Selection\SelectionList;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;

final class RootLoadBranch extends LoadBranch
{
	private SelectionList $selections;

	public function __construct(
		private readonly SelectQuery $query,
		private readonly Closure $allocateAlias,
	) {
		$this->selections = new SelectionList();
		$this->setQueryContext($query, $query, null);
	}

	public function getCollection(): CollectionInterface
	{
		return $this->query->getCollection();
	}

	public function getSelections(): SelectionList
	{
		return $this->selections;
	}

	/**
	 * @param list<string> $fieldNames
	 * @return list<string>
	 */
	public function requireFields(array $fieldNames): array
	{
		if ($fieldNames === []) {
			return [];
		}

		$collection = $this->query->getCollection();
		$aliases = [];

		foreach ($fieldNames as $fieldName) {
			$normalized = $collection->getField($fieldName)->getName();
			$existing = $this->findRootFieldSelection($normalized);

			if ($existing instanceof SelectionItem) {
				$this->selections->add($existing->getExpression(), SelectionTag::REQUIRED);
				$aliases[] = $this->selectionKey($existing);

				continue;
			}

			$alias = ($this->allocateAlias)($normalized);

			if (! is_string($alias) || $alias === '') {
				throw new LogicException('Root field alias allocator must return a non-empty string.');
			}

			if (! $this->query->getSelections()->hasNamedExpression($alias)) {
				$this->query->select($this->query->field($normalized)->as($alias));
			}

			$this->selections->add(
				$this->query->field($normalized)->as($alias),
				[SelectionTag::REQUIRED, SelectionTag::INTERNAL],
			);
			$aliases[] = $alias;
		}

		return $aliases;
	}

	/**
	 * @return non-empty-list<string>
	 */
	public function requirePrimaryKey(): array
	{
		$identityAliases = $this->requireFields($this->getCollection()->getPrimaryKey());

		foreach ($this->getCollection()->getPrimaryKey() as $fieldName) {
			$selection = $this->findRootFieldSelection($this->getCollection()->getField($fieldName)->getName());

			if (! $selection instanceof SelectionItem) {
				continue;
			}

			$tags = [SelectionTag::IDENTITY, SelectionTag::REQUIRED];

			if (! $selection->hasTag(SelectionTag::PUBLIC)) {
				$tags[] = SelectionTag::INTERNAL;
			}

			$this->selections->add($selection->getExpression(), $tags);
		}

		return $identityAliases;
	}

	public function createNode(): RootNode
	{
		$columns = $this->selectionKeys(
			$this->selections->getByTag(SelectionTag::COLUMN),
		);
		$identityAliases = $this->selectionKeys(
			$this->selections->getByTag(SelectionTag::IDENTITY),
		);

		$node = new RootNode($columns, $identityAliases);
		$node->setValueAliases($columns);
		$this->setNode($node);

		return $node;
	}

	public function getRootNode(): RootNode
	{
		$node = $this->getNode();

		if (! $node instanceof RootNode) {
			throw new LogicException('Load branch parser node is not a root node.');
		}

		return $node;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	public function parseRows(array $rows): void
	{
		$node = $this->getRootNode();
		$aliases = $node->getValueAliasTraversal();

		foreach ($rows as $row) {
			$node->parseRow(0, $this->orderedValues($row, $aliases));
		}
	}

	public function registerPublicSelections(): void
	{
		$publicSelections = $this->publicRootSelections();

		if ($publicSelections === []) {
			foreach ($this->query->getCollection()->getVisibleFields() as $fieldName) {
				$this->query->select($this->query->field($fieldName));
			}

			$publicSelections = $this->publicRootSelections();
		}

		foreach ($publicSelections as $selection) {
			$expression = $selection->getExpression();

			if ($expression instanceof StarExpression && $expression->getSource() === $this->query) {
				foreach ($this->query->getCollection()->getVisibleFields() as $fieldName) {
					$this->selections->add($this->query->field($fieldName), SelectionTag::PUBLIC, $selection->isExplicit());
				}

				continue;
			}

			$this->selections->add($selection->getExpression(), SelectionTag::PUBLIC, $selection->isExplicit());
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<string> $aliases
	 * @return list<mixed>
	 */
	private function orderedValues(array $row, array $aliases): array
	{
		$ordered = [];

		foreach ($aliases as $alias) {
			$ordered[] = $row[$alias] ?? null;
		}

		return $ordered;
	}

	private function isInternalSelection(mixed $expression): bool
	{
		return $expression instanceof AliasedExpression
			&& str_starts_with($expression->getAlias(), '__on_data_');
	}

	/**
	 * @return list<SelectionItem>
	 */
	private function publicRootSelections(): array
	{
		return array_values(array_filter(
			$this->query->getSelections()->getExplicit(),
			fn (SelectionItem $selection): bool => ! $this->isInternalSelection($selection->getExpression()),
		));
	}

	private function findRootFieldSelection(string $fieldName): ?SelectionItem
	{
		foreach ($this->selections->getAll() as $selection) {
			$fieldExpression = $selection->getExpression();

			if ($fieldExpression instanceof AliasedExpression) {
				$fieldExpression = $fieldExpression->getExpression();
			}

			if (
				$fieldExpression instanceof FieldRef
				&& $fieldExpression->getSource() === $this->query
				&& $fieldExpression->getField()->getName() === $fieldName
			) {
				return $selection;
			}
		}

		return null;
	}

	private function selectionKey(SelectionItem $selection): string
	{
		return $selection->getSelectionKey();
	}

	/**
	 * @param list<SelectionItem> $selections
	 * @return list<string>
	 */
	private function selectionKeys(array $selections): array
	{
		return array_map($this->selectionKey(...), $selections);
	}
}
