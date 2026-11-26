<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;
use ON\Data\Schema\RepresentationFieldSchema;
use ON\Data\Schema\RepresentationSource;

/**
 * Reads field paths and resolves aliases and primary keys within one source.
 */
final class RepresentationSourceReader
{
	/**
	 * @param callable(string): PathReadResult<mixed> $readPath
	 * @return array{array<string, PathReadResult<mixed>>, array<string, mixed>}
	 */
	public static function read(RepresentationSource $source, callable $readPath): array
	{
		$readsByPath = [];
		foreach ($source->getFields() as $field) {
			$path = $field->getPath();
			$readsByPath[$path] ??= $readPath($path);
		}

		return [$readsByPath, self::agreedPresentValues($source, $readsByPath)];
	}

	/** @param array<string, mixed> $row */
	public static function keyFromRow(RepresentationSource $source, array $row): ?Key
	{
		$values = self::primaryKeyValues($source, static fn (string $path): mixed => $row[$path] ?? null);
		$collection = $source->getCollection();

		return count($values) === count($collection->getPrimaryKey()) ? $collection->getKey($values) : null;
	}

	/**
	 * @param callable(string): mixed $read
	 * @return array<string, mixed>
	 */
	private static function primaryKeyValues(RepresentationSource $source, callable $read): array
	{
		$values = [];
		$primaryKey = $source->getCollection()->getPrimaryKey();
		foreach ($source->getPrimaryKeyPaths() as $index => $path) {
			if ($path !== null && ($value = $read($path)) !== null) {
				$values[$primaryKey[$index]] = $value;
			}
		}

		return $values;
	}

	/** @param array<string, PathReadResult<mixed>> $readsByPath */
	public static function resolveKey(RepresentationSource $source, array $readsByPath, ?Key $identity): ?Key
	{
		$collection = $source->getCollection();
		$primaryKey = $collection->getPrimaryKey();
		$values = self::primaryKeyValues($source, static function (string $path) use ($readsByPath): mixed {
			$result = $readsByPath[$path] ?? null;

			return $result?->isPresent() ? $result->getValue() : null;
		});

		if ($identity !== null && ($conflict = $identity->conflictingIdentityField($values)) !== null) {
			throw new StateException(sprintf(
				"Cannot bind projection source for collection '%s' because intent identity field '%s' disagrees with the representation.",
				$collection->getName(),
				$conflict,
			));
		}

		return $identity ?? (count($values) === count($primaryKey) ? $collection->getKey($values) : null);
	}

	/**
	 * Fold present paths onto collection fields.
	 *
	 * Writable aliases of the same field must agree and win. Read-only aliases
	 * are output: they fill a field only when no writable path is present
	 * (implicit identity, read-only-only projections).
	 *
	 * @param array<string, PathReadResult<mixed>> $readsByPath
	 *
	 * @return array<string, mixed>
	 */
	private static function agreedPresentValues(RepresentationSource $source, array $readsByPath): array
	{
		$writable = [];
		$writablePaths = [];
		$readOnly = [];
		$readOnlyPaths = [];

		foreach ($source->getFields() as $fieldSchema) {
			$result = $readsByPath[$fieldSchema->getPath()] ?? null;
			if (! $result instanceof PathReadResult || ! $result->isPresent()) {
				continue;
			}

			if ($fieldSchema->isWritable()) {
				self::assignPresentAlias(
					$source,
					$writable,
					$writablePaths,
					$fieldSchema,
					$result->getValue(),
				);

				continue;
			}

			self::assignPresentAlias(
				$source,
				$readOnly,
				$readOnlyPaths,
				$fieldSchema,
				$result->getValue(),
			);
		}

		return $writable + array_diff_key($readOnly, $writable);
	}

	/**
	 * @param array<string, mixed> $values
	 * @param array<string, string> $paths
	 */
	private static function assignPresentAlias(
		RepresentationSource $source,
		array &$values,
		array &$paths,
		RepresentationFieldSchema $fieldSchema,
		mixed $value,
	): void {
		$fieldName = $fieldSchema->getFieldName();
		if (array_key_exists($fieldName, $values) && $values[$fieldName] !== $value) {
			throw new StateException(sprintf(
				"Cannot bind projection source for collection '%s' because paths '%s' and '%s' disagree on field '%s'.",
				$source->getCollection()->getName(),
				$paths[$fieldName],
				$fieldSchema->getPath(),
				$fieldName,
			));
		}

		$values[$fieldName] = $value;
		$paths[$fieldName] = $fieldSchema->getPath();
	}
}
