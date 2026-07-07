<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\ManualProjection;

/**
 * User-facing fluent API for manual mutable projections via Session::projection().
 *
 * Exists to orchestrate target creation (from/fromPath/create/existing/tracked),
 * property declaration collection, representation tracking, and final binding
 * merge — while delegating target lifecycle and binding compilation elsewhere.
 */
use InvalidArgumentException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\ORM\Compiler\ProjectionFieldShape;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Session;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBindingMerger;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationFieldStateItem;
use ON\Data\ORM\State\RepresentationState;

final class Builder
{
	/** @var list<ProjectionFieldShape> */
	private array $propertyShapes = [];

	private ?CollectionInterface $pendingCollection = null;

	private ?PathResolution $pendingPath = null;

	private ProjectionTargetFactory $targetFactory;

	public function __construct(
		private Session $session,
		private object $representation,
		private ProjectionCompiler $projectionCompiler = new ProjectionCompiler(),
		?PathResolver $pathResolver = null,
		?RelationApplier $relationApplier = null,
		?RepresentationTracker $representationTracker = null,
		private RepresentationBindingMerger $bindingMerger = new RepresentationBindingMerger(),
	) {
		$pathResolver ??= new PathResolver($this->session->getRepresentations());
		$relationApplier ??= new RelationApplier(
			$this->session->getToManyRelations(),
			$this->session->getToOneRelations()
		);
		$representationTracker ??= new RepresentationTracker(
			$this->session->getRepresentations(),
			$this->session->getRecords()
		);
		$this->targetFactory = new ProjectionTargetFactory(
			$this->session,
			$this->representation,
			$pathResolver,
			$relationApplier,
			$representationTracker,
		);
	}

	public function getRepresentation(): object
	{
		return $this->representation;
	}

	public function from(CollectionInterface $collection): self
	{
		$this->clearPending();
		$this->pendingCollection = $collection;

		return $this;
	}

	public function fromPath(object $owner, string $path): self
	{
		$this->clearPending();
		$this->pendingPath = $this->targetFactory->resolvePath($owner, $path);

		return $this;
	}

	public function tracked(?RelationRef $relation = null, ?object $object = null): RootTarget|Target
	{
		if ($relation instanceof RelationRef) {
			return $this->targetFactory->trackedAtRelation($relation, $this->representation, $object);
		}

		if ($this->pendingCollection !== null) {
			$collection = $this->pendingCollection;
			$this->clearPending();

			return $this->targetFactory->trackedRoot($this->representation, $collection);
		}

		if ($this->pendingPath !== null) {
			$path = $this->pendingPath;
			$this->clearPending();

			return $this->targetFactory->trackedAtPath($path, $this->representation, $object);
		}

		throw new InvalidArgumentException('Builder::tracked() requires from(), fromPath(), or a relation reference.');
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function create(RelationRef|array $relationOrValues = [], array $values = []): RootTarget|Target
	{
		if ($relationOrValues instanceof RelationRef) {
			return $this->targetFactory->createAtRelation($relationOrValues, $values);
		}

		if ($this->pendingCollection !== null) {
			$collection = $this->pendingCollection;
			$this->clearPending();

			return $this->targetFactory->createRoot($collection, $relationOrValues);
		}

		if ($this->pendingPath !== null) {
			$path = $this->pendingPath;
			$this->clearPending();

			return $this->targetFactory->createAtPath($path, $relationOrValues);
		}

		throw new InvalidArgumentException('Builder::create() requires from(), fromPath(), or a relation reference.');
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function existing(RelationRef|Key|array $relationOrKey, Key|array|null $key = null, array $values = []): RootTarget|Target
	{
		if ($relationOrKey instanceof RelationRef) {
			if ($key === null) {
				throw new InvalidArgumentException('Builder::existing() requires a key when identifying a relation target.');
			}

			return $this->targetFactory->existingAtRelation($relationOrKey, $key, $values);
		}

		if ($this->pendingCollection !== null) {
			$collection = $this->pendingCollection;
			$this->clearPending();

			return $this->targetFactory->existingRoot($collection, $relationOrKey, $this->resolveExistingSeedValues($key, $values));
		}

		if ($this->pendingPath !== null) {
			$path = $this->pendingPath;
			$this->clearPending();

			return $this->targetFactory->existingAtPath($path, $relationOrKey, $this->resolveExistingSeedValues($key, $values));
		}

		throw new InvalidArgumentException('Builder::existing() requires from(), fromPath(), or a relation reference.');
	}

	public function properties(PropertyRef|AllProperties ...$items): self
	{
		if ($items === []) {
			throw new InvalidArgumentException('Builder::properties() requires at least one property declaration.');
		}

		foreach ($items as $item) {
			array_push($this->propertyShapes, ...$this->normalizePropertyDeclaration($item));
		}

		return $this;
	}

	public function end(): object
	{
		$state = $this->session->getRepresentations()->get($this->representation);
		$fallbackCollection = $state instanceof RepresentationState
			? $state->getBinding()->getCollection()
			: null;
		$manualBinding = $this->projectionCompiler->compile($this->propertyShapes, $fallbackCollection);
		$recordsByPath = $this->recordsByPathFromShapes();

		if ($state instanceof RepresentationState) {
			$binding = $this->bindingMerger->mergeManualOverlay($state->getBinding(), $manualBinding);
			$fieldItems = $state->getFieldItems();
			$relationItems = $state->getRelationItems();
		} else {
			$binding = $manualBinding;
			$fieldItems = [];
			$relationItems = [];
		}

		foreach ($binding->getFields() as $fieldBinding) {
			if ($this->hasFieldItem($fieldItems, $fieldBinding->getPath())) {
				continue;
			}

			$record = $this->resolveRecordForNewField($state, $fieldBinding, $recordsByPath);
			$fieldItems[] = new RepresentationFieldStateItem(
				$fieldBinding,
				$record,
				$fieldBinding->getFieldName(),
				$record->getRevision()
			);
		}

		if ($state instanceof RepresentationState) {
			$this->session->getRepresentations()->remove($this->representation);
		}

		$this->session->getRepresentations()->add($this->representation, new RepresentationState($binding, $fieldItems, $relationItems));
		$this->propertyShapes = [];

		return $this->representation;
	}

	/**
	 * Resolves the concrete record a newly added manual field item attaches to.
	 *
	 * Resolution is by source path, never by collection name: root-source fields
	 * ([]) attach to the representation's root record, and relation-sourced fields
	 * attach to the record already bound for that source path. Fields declared
	 * through their own source (create()/existing()/tracked()) fall back to that
	 * explicit record; otherwise this throws rather than guessing from the session
	 * record store.
	 *
	 * @param array<string, RecordState> $recordsByPath
	 */
	private function resolveRecordForNewField(
		?RepresentationState $state,
		RepresentationFieldBinding $fieldBinding,
		array $recordsByPath,
	): RecordState {
		if ($state instanceof RepresentationState) {
			$resolved = $this->resolveRecordForFieldBinding($state, $fieldBinding);
			if ($resolved instanceof RecordState) {
				return $resolved;
			}
		}

		$explicit = $recordsByPath[$fieldBinding->getPath()] ?? null;
		if ($explicit instanceof RecordState) {
			if ($explicit->getCollection()->getName() !== $fieldBinding->getCollectionName()) {
				throw new StateException(sprintf(
					"Manual projection field '%s' resolved to a record of collection '%s' but the binding targets collection '%s'.",
					$fieldBinding->getPath(),
					$explicit->getCollection()->getName(),
					$fieldBinding->getCollectionName(),
				));
			}

			return $explicit;
		}

		throw new StateException(sprintf(
			"Cannot attach manual projection field '%s' because no concrete record state for source path '%s' could be resolved.",
			$fieldBinding->getPath(),
			$fieldBinding->getSourcePathKey(),
		));
	}

	/**
	 * Resolves the concrete record for a field binding from an existing tracked
	 * representation state by its source path, never by collection name.
	 */
	private function resolveRecordForFieldBinding(
		RepresentationState $state,
		RepresentationFieldBinding $fieldBinding,
	): ?RecordState {
		if ($fieldBinding->isRootSource()) {
			return $state->getRootRecord();
		}

		$sourceKey = $fieldBinding->getSourcePathKey();
		foreach ($state->getFieldItems() as $item) {
			if ($item->getBinding()->getSourcePathKey() === $sourceKey) {
				return $item->getRecord();
			}
		}

		return null;
	}

	/**
	 * Maps each declared manual field path to the concrete record its source
	 * resolves to, so field attachment never scans all session records.
	 *
	 * @return array<string, RecordState>
	 */
	private function recordsByPathFromShapes(): array
	{
		$records = [];
		foreach ($this->propertyShapes as $shape) {
			$source = $shape->getSource();
			if ($source instanceof PropertySource) {
				$records[$shape->getPublicPath()] = $source->getTargetRecord();
			}
		}

		return $records;
	}

	/**
	 * @param list<RepresentationFieldStateItem> $items
	 */
	private function hasFieldItem(array $items, string $path): bool
	{
		foreach ($items as $item) {
			if ($item->getPath() === $path) {
				return true;
			}
		}

		return false;
	}

	private function clearPending(): void
	{
		$this->pendingCollection = null;
		$this->pendingPath = null;
	}

	/**
	 * Root/path `existing($key, $values)` passes seed values as the second argument.
	 *
	 * @param array<string, mixed> $values
	 *
	 * @return array<string, mixed>
	 */
	private function resolveExistingSeedValues(Key|array|null $key, array $values): array
	{
		if ($values !== []) {
			return $values;
		}

		if (is_array($key)) {
			return $key;
		}

		if ($key instanceof Key) {
			return $key->getValues();
		}

		return [];
	}

	/**
	 * @return list<ProjectionFieldShape>
	 */
	private function normalizePropertyDeclaration(PropertyRef|AllProperties $item): array
	{
		if ($item instanceof AllProperties) {
			$shapes = [];
			foreach ($item->getSource()->getTargetRecord()->getCollection()->getFields() as $field) {
				$shapes[] = new ProjectionFieldShape(
					$field->getName(),
					$item->getSource(),
					$field->getName(),
				);
			}

			return $shapes;
		}

		return [
			new ProjectionFieldShape(
				$item->getPublicPath(),
				$item->getSource(),
				$item->getFieldName(),
			),
		];
	}
}
