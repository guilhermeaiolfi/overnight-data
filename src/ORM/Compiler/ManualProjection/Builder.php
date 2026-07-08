<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\ManualProjection;

/**
 * User-facing fluent API for manual mutable projections via Session::projection().
 *
 * Exists to orchestrate target creation (from/fromPath/create/existing/tracked),
 * property declaration collection, representation tracking, and binding
 * compilation while delegating target lifecycle elsewhere.
 */
use InvalidArgumentException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\ORM\Compiler\ProjectionFieldShape;
use ON\Data\ORM\Session;
use ON\Data\ORM\State\RepresentationState;

final class Builder
{
	/** @var list<ProjectionFieldShape> */
	private array $propertyShapes = [];

	private ?CollectionInterface $pendingCollection = null;

	private ?PathResolution $pendingPath = null;

	private ProjectionTargetFactory $targetFactory;
	private RepresentationTracker $representationTracker;

	public function __construct(
		private Session $session,
		private object $representation,
		private ProjectionCompiler $projectionCompiler = new ProjectionCompiler(),
		?PathResolver $pathResolver = null,
		?RepresentationTracker $representationTracker = null,
	) {
		$pathResolver ??= new PathResolver($this->session->getRepresentations());
		$representationTracker ??= new RepresentationTracker(
			$this->session->getRepresentations(),
			$this->session->getRecords()
		);
		$this->representationTracker = $representationTracker;
		$this->targetFactory = new ProjectionTargetFactory(
			$this->session,
			$this->representation,
			$pathResolver,
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
			? $state->getSchema()->getCollection()
			: null;
		$manualSchema = $this->projectionCompiler->compile($this->propertyShapes, $fallbackCollection);
		$this->representationTracker->applyManualProjection(
			$this->representation,
			$manualSchema,
			$this->propertyShapes,
		);
		$this->propertyShapes = [];

		return $this->representation;
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
