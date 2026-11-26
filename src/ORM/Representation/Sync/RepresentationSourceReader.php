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

	/** @param array<string, PathReadResult<mixed>> $readsByPath */
	public static function resolveKey(RepresentationSource $source, array $readsByPath, ?Key $identity): ?Key
	{
		$collection = $source->getCollection();
		$values = [];
		$primaryKey = $collection->getPrimaryKey();
		foreach ($source->getPrimaryKeyPaths() as $index => $path) {
			$result = $path === null ? null : ($readsByPath[$path] ?? null);
			if ($result !== null && $result->isPresent()) {
				$value = $result->getValue();
				if ($value !== null) {
					$values[$primaryKey[$index]] = $value;
				}
			}
		}

		$key = count($values) === count($primaryKey) ? $collection->getKey($values) : null;
		if ($identity !== null && ($conflict = $identity->conflictingIdentityField($key?->getValues() ?? $values)) !== null) {
			throw new StateException(sprintf(
				"Cannot bind projection source for collection '%s' because intent identity field '%s' disagrees with the representation.",
				$collection->getName(),
				$conflict,
			));
		}

		return $key ?? $identity;
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
