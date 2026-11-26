<?php

declare(strict_types=1);

namespace ON\Data\Schema;

use ON\Data\Definition\Collection\CollectionInterface;

/** @internal Shared field assembly for representation schema compilers. */
final class SchemaFields
{
	/**
	 * @return list<RepresentationFieldSchema>
	 */
	public static function defaultFields(CollectionInterface $collection): array
	{
		$fields = [];

		foreach ($collection->getFields() as $field) {
			if ($field->isHidden()) {
				continue;
			}

			$fields[] = new RepresentationFieldSchema(
				$field->getName(),
				$collection,
				$field->getName(),
				writable: true,
			);
		}

		return $fields;
	}

	public static function defaultSchema(CollectionInterface $collection): RepresentationSchema
	{
		$schema = new RepresentationSchema($collection);

		foreach (self::defaultFields($collection) as $field) {
			$schema->addField($field);
		}

		self::assemblePrimaryKeyFields($schema, $collection);

		return $schema;
	}

	public static function assembleFlatSourcePrimaryKeys(RepresentationSchema $schema): void
	{
		$seen = [];

		foreach ($schema->getFields() as $field) {
			if ($field->getSourcePath() === []) {
				continue;
			}

			$key = $field->getSourcePathKey();
			if (isset($seen[$key])) {
				continue;
			}

			$seen[$key] = true;
			self::assemblePrimaryKeyFields($schema, $field->getCollection(), $field->getSourcePath());
		}
	}

	/**
	 * @param list<string> $sourcePath
	 */
	public static function assemblePrimaryKeyFields(
		RepresentationSchema $schema,
		CollectionInterface $collection,
		array $sourcePath = [],
	): void {
		foreach ($collection->getPrimaryKey() as $fieldName) {
			if ($schema->hasFieldForSource($sourcePath, $fieldName)) {
				continue;
			}

			$schema->addField(new RepresentationFieldSchema(
				self::identityPath($schema, $sourcePath, $fieldName),
				$collection,
				$fieldName,
				writable: false,
				sourcePath: $sourcePath,
				role: RepresentationFieldRole::Implicit,
			));
		}
	}

	/**
	 * @param list<string> $sourcePath
	 */
	private static function identityPath(RepresentationSchema $schema, array $sourcePath, string $fieldName): string
	{
		$path = $sourcePath === []
			? $fieldName
			: implode('.', [...$sourcePath, $fieldName]);

		if (! $schema->hasPath($path)) {
			return $path;
		}

		$n = 2;
		do {
			$fallback = $path . '_' . $n++;
		} while ($schema->hasPath($fallback));

		return $fallback;
	}
}
