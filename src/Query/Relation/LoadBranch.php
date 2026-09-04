<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\Exception\LoadRuntimeException;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\Selection\SelectionList;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;
use ON\Data\Schema\RepresentationSchema;

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
	 * @var array<string, PathBinding>
	 */
	private array $pathBindings = [];

	/**
	 * @var list<string>
	 */
	private array $requiredFieldNames = [];

	private ?RepresentationSchema $representationSchema = null;

	/**
	 * Bind this branch's schema node for the current fetch.
	 */
	public function setRepresentationSchema(RepresentationSchema $schema): void
	{
		$this->representationSchema = $schema;
	}

	public function getRepresentationSchema(): RepresentationSchema
	{
		return $this->representationSchema ?? throw LoadRuntimeException::schemaMissing();
	}

	public function bindPathToLoadKey(string $path, string $loadKey): void
	{
		$this->pathBindings[$path] = new PathBinding($loadKey);
	}

	/**
	 * @param list<string> $relativePath relation names from this branch to the child destination
	 */
	public function bindPathToChildDestination(string $path, array $relativePath, string $loadKey): void
	{
		$this->pathBindings[$path] = new PathBinding($loadKey, array_values($relativePath));
	}

	public function hasPathBinding(string $path): bool
	{
		return isset($this->pathBindings[$path]);
	}

	public function loadKeyForPath(string $path): string
	{
		return ($this->pathBindings[$path] ?? null)?->getLoadKey() ?? $path;
	}

	/**
	 * Local parser remap: bag name => SQL/result key. Own-level collection
	 * fields use the field name as the bag key; local flats and non-field
	 * internals use the output name. Child-bag flats are omitted.
	 *
	 * Built from {@see PathBinding} after the schema-path planner runs; not a
	 * second walk of {@see SelectionList} COLUMN tags as schema paths.
	 *
	 * @return array<string, string>
	 */
	public function getRemapColumns(): array
	{
		$map = [];

		foreach ($this->pathBindings as $path => $binding) {
			if ($binding->isChildDestination()) {
				continue;
			}

			$name = $this->bagKeyForPath($path);

			if (isset($map[$name])) {
				continue;
			}

			$map[$name] = $binding->getLoadKey();
		}

		return $map;
	}

	/**
	 * @return list<string>|null
	 */
	public function childPathForPath(string $path): ?array
	{
		return ($this->pathBindings[$path] ?? null)?->getChildPath();
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
	 * Projection level this branch writes onto: root {@see SelectQuery} or nested {@see RelationRef}.
	 */
	abstract public function getProjectionLevel(): SelectQuery|RelationRef;

	abstract public function getSelections(): SelectionList;

	/**
	 * Register own-level fields as REQUIRED. Returns schema paths (not SQL aliases).
	 * {@see LoadFieldPlanner::emitRequiredFields()} emits SQL after schema bind.
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
			$this->requiredFieldNames[] = $normalized;
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
	 * @return list<string>
	 */
	public function getRequiredFieldNames(): array
	{
		return array_values(array_unique($this->requiredFieldNames));
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
	 * Parser-bag key for a schema path (output name): own collection field name, or
	 * the output name for flats / internals.
	 */
	public function bagKeyForPath(string $path): string
	{
		if ($this->childPathForPath($path) !== null) {
			return $this->loadKeyForPath($path);
		}

		$selection = $this->getSelections()->findBySelectionKey($path);

		if (! $selection instanceof SelectionItem) {
			return $path;
		}

		return $this->parserColumnName($selection);
	}

	private function ownFieldExpression(string $fieldName): AliasedExpression
	{
		$level = $this->getProjectionLevel();

		return $level->field($fieldName)->as($fieldName);
	}
}
