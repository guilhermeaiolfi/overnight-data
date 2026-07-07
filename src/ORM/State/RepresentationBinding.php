<?php

declare(strict_types=1);

namespace ON\Data\ORM\State;

/**
 * Persistence provenance graph for one representation shape: a root collection
 * plus field and relation path maps that describe how object properties map to
 * records.
 *
 * The root collection means this representation branch is rooted at that
 * collection. Individual field properties may still target other collections in
 * flat heterogeneous projections, and nested relation bindings carry related
 * bindings rooted at the related collection.
 *
 * Exists as the durable ORM model compiled from queries or manual projections
 * and consumed by sync, adoption, and relation runtime state — separate from
 * query selections and mapper hydration.
 */
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Exception\StateException;

final class RepresentationBinding
{
	/** @var array<string, RepresentationFieldBinding> */
	private array $fields = [];
	/** @var array<string, RepresentationRelationBinding> */
	private array $relations = [];
	/** @var list<string> */
	private array $paths = [];

	public function __construct(
		private CollectionInterface $collection,
	) {
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getCollectionName(): string
	{
		return $this->collection->getName();
	}

	public function addField(RepresentationFieldBinding $binding): void
	{
		$path = $binding->getPath();
		$this->assertPathIsAvailable($path);

		$this->fields[$path] = $binding;
		$this->paths[] = $path;
	}

	public function hasField(string $path): bool
	{
		return array_key_exists($path, $this->fields);
	}

	public function getField(string $path): RepresentationFieldBinding
	{
		if (! array_key_exists($path, $this->fields)) {
			throw new StateException(sprintf("Representation binding does not contain field path '%s'.", $path));
		}

		return $this->fields[$path];
	}

	/**
	 * @return list<RepresentationFieldBinding>
	 */
	public function getFields(): array
	{
		return array_values($this->fields);
	}

	/**
	 * @return list<RepresentationFieldBinding>
	 */
	public function getWritableFieldBindings(): array
	{
		return array_values(array_filter(
			$this->fields,
			static fn (RepresentationFieldBinding $binding): bool => $binding->isWritable()
		));
	}

	/**
	 * @return list<RepresentationFieldBinding>
	 */
	public function getReadOnlyFieldBindings(): array
	{
		return array_values(array_filter(
			$this->fields,
			static fn (RepresentationFieldBinding $binding): bool => $binding->isReadOnly()
		));
	}

	public function getFieldFor(
		CollectionInterface|string $collection,
		string $fieldName,
	): ?RepresentationFieldBinding {
		$collectionName = $collection instanceof CollectionInterface ? $collection->getName() : $collection;

		foreach ($this->fields as $field) {
			if ($field->getCollectionName() === $collectionName && $field->getFieldName() === $fieldName) {
				return $field;
			}
		}

		return null;
	}

	public function hasFieldFor(CollectionInterface|string $collection, string $fieldName): bool
	{
		return $this->getFieldFor($collection, $fieldName) instanceof RepresentationFieldBinding;
	}

	/**
	 * @param list<string> $sourcePath
	 */
	public function getFieldForSource(array $sourcePath, string $fieldName): ?RepresentationFieldBinding
	{
		$sourceKey = implode('.', $sourcePath);

		foreach ($this->fields as $field) {
			if ($field->getSourcePathKey() === $sourceKey && $field->getFieldName() === $fieldName) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * @param list<string> $sourcePath
	 */
	public function hasFieldForSource(array $sourcePath, string $fieldName): bool
	{
		return $this->getFieldForSource($sourcePath, $fieldName) instanceof RepresentationFieldBinding;
	}

	public function addRelation(RepresentationRelationBinding $binding): void
	{
		$path = $binding->getPath();
		$this->assertPathIsAvailable($path);

		$this->relations[$path] = $binding;
		$this->paths[] = $path;
	}

	public function hasRelation(string $path): bool
	{
		return array_key_exists($path, $this->relations);
	}

	public function getRelation(string $path): RepresentationRelationBinding
	{
		if (! array_key_exists($path, $this->relations)) {
			throw new StateException(sprintf("Representation binding does not contain relation path '%s'.", $path));
		}

		return $this->relations[$path];
	}

	/**
	 * @return list<RepresentationRelationBinding>
	 */
	public function getRelations(): array
	{
		return array_values($this->relations);
	}

	public function hasPath(string $path): bool
	{
		return $this->hasField($path) || $this->hasRelation($path);
	}

	/**
	 * @return list<string>
	 */
	public function getPaths(): array
	{
		return $this->paths;
	}

	private function assertPathIsAvailable(string $path): void
	{
		if ($this->hasPath($path)) {
			throw new StateException(sprintf("Representation binding already contains path '%s'.", $path));
		}
	}
}
