<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\Selection\SelectionList;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;

abstract class LoadBranch
{
	private ?AbstractNode $node = null;

	private ?SelectQuery $query = null;

	private ?QuerySourceInterface $source = null;

	private ?RelationRef $queryLocalRelation = null;

	private ?string $publicPayloadChild = null;

	/**
	 * @var list<RelationLoadBranch>
	 */
	private array $children = [];

	/**
	 * @var array<string, PlaceBinding>
	 */
	private array $placeBindings = [];

	public function bindPlaceToLoadKey(string $placeKey, string $loadKey): void
	{
		$this->placeBindings[$placeKey] = new PlaceBinding($loadKey);
	}

	/**
	 * @param list<string> $relativePath relation names from this branch to the child destination
	 */
	public function bindPlaceToChildDestination(string $placeKey, array $relativePath, string $loadKey): void
	{
		$this->placeBindings[$placeKey] = new PlaceBinding($loadKey, array_values($relativePath));
	}

	public function hasPlaceBinding(string $placeKey): bool
	{
		return isset($this->placeBindings[$placeKey]);
	}

	public function loadKeyForPlace(string $placeKey): string
	{
		return ($this->placeBindings[$placeKey] ?? null)?->getLoadKey() ?? $placeKey;
	}

	/**
	 * Local parser remap: bag name => SQL/result key. Own-level collection
	 * fields use the field name as the bag key; local flats and non-field
	 * internals use the output name. Child-bag flats are omitted.
	 *
	 * @return array<string, string>
	 */
	public function getRemapColumns(): array
	{
		$map = [];

		foreach ($this->getSelections()->getByTag(SelectionTag::COLUMN) as $selection) {
			if ($this->childPathForPlace($selection->getSelectionKey()) !== null) {
				continue;
			}

			$name = $this->parserColumnName($selection);

			if (isset($map[$name])) {
				continue;
			}

			$map[$name] = $this->sqlAliasForColumn($name);
		}

		return $map;
	}

	private function sqlAliasForColumn(string $column): string
	{
		$own = $this->findOwnFieldSelection($column);

		if ($own instanceof SelectionItem) {
			return $this->loadKeyForPlace($own->getSelectionKey());
		}

		return $this->loadKeyForPlace($column);
	}

	/**
	 * @return list<string>|null
	 */
	public function childPathForPlace(string $placeKey): ?array
	{
		return ($this->placeBindings[$placeKey] ?? null)?->getChildPath();
	}

	public function setNode(AbstractNode $node): void
	{
		$this->node = $node;
	}

	public function getNode(): AbstractNode
	{
		return $this->node ?? throw new LogicException('Load branch parser node is not registered.');
	}

	public function hasNode(): bool
	{
		return $this->node !== null;
	}

	abstract public function getCollection(): CollectionInterface;

	/**
	 * Projection level this branch places onto: root {@see SelectQuery} or nested {@see RelationRef}.
	 */
	abstract public function getProjectionLevel(): SelectQuery|RelationRef;

	abstract public function getSelections(): SelectionList;

	/**
	 * Register own-level fields as REQUIRED. Returns place keys (not SQL aliases).
	 * SQL emit and load-local keys belong on the runtime requireFields path.
	 *
	 * @param list<string> $fieldNames
	 * @return list<string>
	 */
	public function requireFields(array $fieldNames): array
	{
		if ($fieldNames === []) {
			return [];
		}

		$added = [];

		foreach ($fieldNames as $fieldName) {
			$normalized = $this->getCollection()->getField($fieldName)->getName();
			$existing = $this->findOwnFieldSelection($normalized);

			if ($existing instanceof SelectionItem) {
				$this->getSelections()->add($existing->getExpression(), SelectionTag::REQUIRED);
				$added[] = $existing->getSelectionKey();

				continue;
			}

			$this->getSelections()->add(
				$this->ownFieldExpression($normalized),
				[SelectionTag::REQUIRED, SelectionTag::INTERNAL],
			);
			$added[] = $normalized;
		}

		return $added;
	}

	/**
	 * @return non-empty-list<string>
	 */
	public function requirePrimaryKey(): array
	{
		return $this->requireFields($this->getCollection()->getPrimaryKey());
	}

	public function setPublicPayloadChild(?string $container): void
	{
		$this->publicPayloadChild = $container;
	}

	public function getPublicPayloadChild(): ?string
	{
		return $this->publicPayloadChild;
	}

	/**
	 * Store the query/source chosen during the initial load-planning stage.
	 */
	public function setQueryContext(
		SelectQuery $query,
		QuerySourceInterface $source,
		?RelationRef $queryLocalRelation,
	): void {
		$this->query = $query;
		$this->source = $source;
		$this->queryLocalRelation = $queryLocalRelation;
	}

	public function getQuery(): SelectQuery
	{
		return $this->query ?? throw new LogicException('Load branch query context is not configured.');
	}

	public function getSource(): QuerySourceInterface
	{
		return $this->source ?? throw new LogicException('Load branch source context is not configured.');
	}

	public function getQueryLocalRelation(): ?RelationRef
	{
		return $this->queryLocalRelation;
	}

	/**
	 * @return list<RelationLoadBranch>
	 */
	public function getChildren(): array
	{
		return $this->children;
	}

	protected function addChild(RelationLoadBranch $child): void
	{
		$this->children[] = $child;
	}

	protected function findOwnFieldSelection(string $fieldName): ?SelectionItem
	{
		$level = $this->getProjectionLevel();

		foreach ($this->getSelections()->getAll() as $selection) {
			$expression = $selection->getExpression();

			if ($expression instanceof AliasedExpression) {
				$expression = $expression->getExpression();
			}

			if (
				$expression instanceof FieldRef
				&& $expression->getSource() === $level
				&& $expression->getField()->getName() === $fieldName
			) {
				return $selection;
			}
		}

		return null;
	}

	private function parserColumnName(SelectionItem $selection): string
	{
		$fieldRef = $selection->getFieldRef();
		$level = $this->getProjectionLevel();

		if ($fieldRef instanceof FieldRef && $fieldRef->getSource() === $level) {
			return $fieldRef->getField()->getName();
		}

		return $selection->getSelectionKey();
	}

	/**
	 * Parser-bag key for a place (output name): own collection field name, or
	 * the output name for flats / internals.
	 */
	public function bagKeyForPlace(string $placeKey): string
	{
		if ($this->childPathForPlace($placeKey) !== null) {
			return $this->loadKeyForPlace($placeKey);
		}

		$selection = $this->getSelections()->findBySelectionKey($placeKey);

		if (! $selection instanceof SelectionItem) {
			return $placeKey;
		}

		return $this->parserColumnName($selection);
	}

	private function ownFieldExpression(string $fieldName): AliasedExpression
	{
		$level = $this->getProjectionLevel();

		return $level->field($fieldName)->as($fieldName);
	}
}
