<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use Closure;
use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Result\Parser\RootNode;
use ON\Data\Query\SelectQuery;

final class RootLoadBranch extends LoadBranch
{
	/**
	 * @var list<string>
	 */
	private array $columns = [];

	/**
	 * @var list<string>
	 */
	private array $valueAliases = [];

	/**
	 * @var array<string, true>
	 */
	private array $publicColumns = [];

	/**
	 * @var array<string, string>
	 */
	private array $fieldParserNames = [];

	/**
	 * @var list<string>
	 */
	private array $identityAliases = [];

	public function __construct(
		private readonly SelectQuery $query,
		private readonly Closure $allocateAlias,
	) {
		$this->setQueryContext($query, $query, null);
	}

	public function addPublicColumn(string $alias, ?string $fieldName = null): void
	{
		$this->columns[] = $alias;
		$this->valueAliases[] = $alias;
		$this->publicColumns[$alias] = true;

		if ($fieldName !== null) {
			$this->fieldParserNames[$fieldName] = $alias;
		}
	}

	public function getCollection(): CollectionInterface
	{
		return $this->query->getCollection();
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

			if (isset($this->fieldParserNames[$normalized])) {
				$aliases[] = $this->fieldParserNames[$normalized];

				continue;
			}

			$alias = ($this->allocateAlias)($normalized);

			if (! is_string($alias) || $alias === '') {
				throw new LogicException('Root field alias allocator must return a non-empty string.');
			}

			if (! $this->query->getSelections()->hasNamedExpression($alias)) {
				$this->query->select($this->query->field($normalized)->as($alias));
			}

			$this->fieldParserNames[$normalized] = $alias;
			$this->columns[] = $alias;
			$this->valueAliases[] = $alias;
			$aliases[] = $alias;
		}

		return $aliases;
	}

	/**
	 * @return list<string>
	 */
	public function getColumns(): array
	{
		return $this->columns;
	}

	/**
	 * @return list<string>
	 */
	public function getValueAliases(): array
	{
		return $this->valueAliases;
	}

	/**
	 * @return array<string, true>
	 */
	public function getPublicColumns(): array
	{
		return $this->publicColumns;
	}

	/**
	 * @return non-empty-list<string>
	 */
	public function requirePrimaryKey(): array
	{
		/** @var non-empty-list<string> $identityAliases */
		$identityAliases = parent::requirePrimaryKey();
		$this->identityAliases = $identityAliases;

		return $identityAliases;
	}

	public function createNode(): RootNode
	{
		$node = new RootNode($this->columns, $this->identityAliases);
		$node->setValueAliases($this->valueAliases);
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

	/**
	 * @return list<array<string, mixed>>
	 */
	public function buildOutputRecords(): array
	{
		$cleaned = [];

		foreach ($this->getRootNode()->getResult() as $record) {
			$item = [];

			foreach ($record as $key => $value) {
				if (isset($this->publicColumns[$key])) {
					$item[$key] = $value;
				}
			}

			foreach ($this->getChildren() as $child) {
				$name = $child->getRelationRef()->getName();
				$value = $record[$name] ?? ($child->returnsMany() ? [] : null);

				if ($child->getSelection()->isVisible()) {
					$item[$name] = $child->buildVisibleOutput($value);

					continue;
				}

				$this->mergePromotions($item, $child->collectHiddenOutput($value), 'root');
			}

			$cleaned[] = $item;
		}

		return $cleaned;
	}

	public function registerPublicSelections(): void
	{
		$publicSelections = array_values(array_filter(
			$this->query->getSelections()->getExplicit(),
			fn ($selection): bool => ! $this->isInternalSelection($selection->getExpression()),
		));

		if ($publicSelections === []) {
			foreach ($this->query->getCollection()->getVisibleFields() as $fieldName) {
				$this->query->select($this->query->field($fieldName));
			}

			$publicSelections = $this->query->getSelections()->getExplicit();
		}

		foreach ($publicSelections as $selection) {
			$expression = $selection->getExpression();
			$alias = $expression instanceof AliasedExpression
				? $expression->getAlias()
				: implode('.', $expression->getPath());

			$fieldExpression = $expression instanceof AliasedExpression
				? $expression->getExpression()
				: $expression;

			$fieldName = null;
			if ($fieldExpression instanceof FieldRef && $fieldExpression->getSource() === $this->query) {
				$fieldName = $fieldExpression->getField()->getName();
			}

			$this->addPublicColumn($alias, $fieldName);
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
}
