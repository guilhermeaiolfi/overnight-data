<?php

declare(strict_types=1);

namespace ON\Data\Schema;

use ON\Data\Definition\Collection\CollectionInterface;

/**
 * Place fields grouped by originating record (one collection + source path).
 *
 * {@see getFields()} is every placement on this record, including extra aliases.
 * {@see getBinding()} is the unique collection-field → first placement map.
 *
 * Carries only structure. External per-source identities for adoption are
 * supplied by the ORM identity maps.
 *
 * Prefer {@see RepresentationSchema::getSources()} for the memoized list; this factory
 * remains for one-off derivation.
 */
final class RepresentationSource
{
	/** @var list<string> */
	private array $path;
	/** @var list<RepresentationFieldSchema> */
	private array $fields;
	/** @var array<string, RepresentationFieldSchema> */
	private array $bindings = [];

	/**
	 * @param list<string> $path
	 * @param list<RepresentationFieldSchema> $fields
	 */
	public function __construct(
		array $path,
		private CollectionInterface $collection,
		array $fields,
	) {
		$this->path = array_values($path);
		$this->fields = array_values($fields);

		foreach ($this->fields as $field) {
			$name = $field->getFieldName();
			if (! array_key_exists($name, $this->bindings)) {
				$this->bindings[$name] = $field;
			}
		}
	}

	/**
	 * @return list<string>
	 */
	public function getPath(): array
	{
		return $this->path;
	}

	public function getPathKey(): string
	{
		return RepresentationFieldSchema::sourcePathKey($this->path);
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	/**
	 * @return list<RepresentationFieldSchema>
	 */
	public function getFields(): array
	{
		return $this->fields;
	}

	public function isRoot(): bool
	{
		return $this->path === [];
	}

	public function hasField(string $fieldName): bool
	{
		return $this->getBinding($fieldName) instanceof RepresentationFieldSchema;
	}

	/**
	 * First placement of this collection field on this source.
	 */
	public function getBinding(string $fieldName): ?RepresentationFieldSchema
	{
		return $this->bindings[$fieldName] ?? null;
	}

	public function getFieldPath(string $fieldName): ?string
	{
		return $this->getBinding($fieldName)?->getPath();
	}

	/**
	 * @return list<RepresentationSource>
	 */
	public static function fromRepresentationSchema(RepresentationSchema $schema): array
	{
		/** @var array<string, list<RepresentationFieldSchema>> $fieldsByPath */
		$fieldsByPath = [];
		/** @var array<string, list<string>> $pathsByKey */
		$pathsByKey = [];
		/** @var array<string, CollectionInterface> $collectionsByKey */
		$collectionsByKey = [];

		foreach ($schema->getFields() as $field) {
			$key = $field->getSourcePathKey();
			$fieldsByPath[$key][] = $field;
			$pathsByKey[$key] ??= $field->getSourcePath();
			$collectionsByKey[$key] ??= $field->getCollection();
		}

		$sources = [];
		foreach ($fieldsByPath as $key => $fields) {
			$sources[] = new self($pathsByKey[$key], $collectionsByKey[$key], $fields);
		}

		return $sources;
	}

	/**
	 * @param list<self> $sources
	 */
	public static function listHasNonRoot(array $sources): bool
	{
		foreach ($sources as $source) {
			if (! $source->isRoot()) {
				return true;
			}
		}

		return false;
	}
}
