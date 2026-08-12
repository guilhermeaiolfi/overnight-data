<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
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
	) {
		$this->selections = new SelectionList();
		$this->setQueryContext($query, $query, null);
	}

	public function getCollection(): CollectionInterface
	{
		return $this->query->getCollection();
	}

	public function getProjectionLevel(): SelectQuery
	{
		return $this->query;
	}

	public function getSelections(): SelectionList
	{
		return $this->selections;
	}

	/**
	 * @return non-empty-list<string>
	 */
	public function requirePrimaryKey(): array
	{
		$placeKeys = $this->requireFields($this->getCollection()->getPrimaryKey());

		foreach ($this->getCollection()->getPrimaryKey() as $fieldName) {
			$selection = $this->findOwnFieldSelection($this->getCollection()->getField($fieldName)->getName());

			if (! $selection instanceof SelectionItem) {
				continue;
			}

			$tags = [SelectionTag::IDENTITY, SelectionTag::REQUIRED];

			// User-authored PK stays visible; required-only PK is INTERNAL.
			if (! $selection->isExplicit()) {
				$tags[] = SelectionTag::INTERNAL;
			}

			$this->selections->add($selection->getExpression(), $tags);
		}

		return $placeKeys;
	}

	public function createNode(): RootNode
	{
		$placeColumns = [];

		foreach ($this->selections->getByTag(SelectionTag::COLUMN) as $selection) {
			$placeKey = $selection->getSelectionKey();

			if ($this->childPathForPlace($placeKey) !== null) {
				continue;
			}

			$placeColumns[] = $placeKey;
		}

		$placeIdentities = array_map(
			static fn (SelectionItem $selection): string => $selection->getSelectionKey(),
			$this->selections->getByTag(SelectionTag::IDENTITY),
		);
		$loadColumns = array_map($this->loadKeyForPlace(...), $placeColumns);
		$loadIdentities = array_map($this->loadKeyForPlace(...), $placeIdentities);

		$node = new RootNode($loadColumns, $loadIdentities);
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
					$this->selections->add(
						$this->query->field($fieldName),
						$selection->isExplicit() ? [SelectionTag::EXPLICIT] : [],
					);
				}

				continue;
			}

			$tags = $selection->isExplicit() ? [SelectionTag::EXPLICIT] : [];
			$this->selections->add($selection->getExpression(), $tags);
		}

		$this->registerInternalSelections();
	}

	private function registerInternalSelections(): void
	{
		foreach ($this->query->getSelections()->getByTag(SelectionTag::INTERNAL) as $selection) {
			$tags = [SelectionTag::INTERNAL, SelectionTag::COLUMN];
			if ($selection->isExplicit()) {
				$tags[] = SelectionTag::EXPLICIT;
			}

			$this->selections->add($selection->getExpression(), $tags);
		}
	}

	/**
	 * @return list<SelectionItem>
	 */
	private function publicRootSelections(): array
	{
		return array_values(array_filter(
			$this->query->getSelections()->getExplicit(),
			static fn (SelectionItem $selection): bool => ! $selection->hasTag(SelectionTag::INTERNAL)
				&& ! $selection->hasTag(SelectionTag::SQL_ONLY),
		));
	}
}
