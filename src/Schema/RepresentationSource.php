<?php

declare(strict_types=1);

namespace ON\Data\Schema;

use ON\Data\Definition\Collection\CollectionInterface;

/**
 * Place fields grouped by originating record (one collection + source path).
 *
 * {@see getFields()} is the only inventory: every placement on this record.
 * {@see getBinding()} is a lookup of the first placement for a collection field.
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
		foreach ($this->fields as $field) {
			if ($field->getFieldName() === $fieldName) {
				return $field;
			}
		}

		return null;
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
