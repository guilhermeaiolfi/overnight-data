<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Shape;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationIdentityColumns;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;

/**
 * Structural grouping of representation fields that originate from one source path.
 *
 * Carries only representation/source structure. Query result aliases and row
 * keys stay in QueryRepresentationIdentityColumns.
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
		return $this->getFieldPath($fieldName) !== null;
	}

	public function getFieldPath(string $fieldName): ?string
	{
		foreach ($this->fields as $field) {
			if ($field->getFieldName() === $fieldName) {
				return $field->getPath();
			}
		}

		return null;
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
}
