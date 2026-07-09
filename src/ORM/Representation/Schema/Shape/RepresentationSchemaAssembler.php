<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Shape;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
/**
 * Shared structural schema creation for representation schema compilation: turns normalized
 * RepresentationFieldShape values into RepresentationFieldSchema entries, and offers
 * default/primary-key shape factories so root, default, and PK fields flow
 * through the same shape path as explicit selections.
 *
 * Exists so SelectQuery and manual representation compilers share one query-agnostic,
 * manual-projection-agnostic place that resolves sources and applies writability
 * / skip-when-missing flags.
 */
final class RepresentationSchemaAssembler
{
	/**
	 * @param list<RepresentationFieldShape> $fieldShapes
	 */
	public function assemble(
		array $fieldShapes,
		RepresentationSourceResolverInterface $resolver,
		CollectionInterface $collection,
		bool $skipWhenMissing = false,
	): RepresentationSchema {
		$schema = new RepresentationSchema($collection);
		$this->assembleInto($schema, $fieldShapes, $resolver, $skipWhenMissing);

		return $schema;
	}

	/**
	 * @param list<RepresentationFieldShape> $fieldShapes
	 */
	public function assembleInto(
		RepresentationSchema $schema,
		array $fieldShapes,
		RepresentationSourceResolverInterface $resolver,
		bool $skipWhenMissing = false,
	): void {
		foreach ($fieldShapes as $shape) {
			if ($schema->hasField($shape->getPublicPath())) {
				continue;
			}

			$resolved = $resolver->resolve($shape->getSource());
			$schema->addField(new RepresentationFieldSchema(
				$shape->getPublicPath(),
				$resolved->getCollection(),
				$shape->getFieldName(),
				writable: $shape->isWritable(),
				skipWhenMissing: $skipWhenMissing,
				sourcePath: $resolved->getSourcePath(),
			));
		}
	}

	/**
	 * Builds one writable scalar field shape per collection field, all rooted at
	 * the given source. Callers pair this with a resolver that maps $source to the
	 * collection so default field schemas flow through the same shape path as
	 * explicit selections.
	 *
	 * @return list<RepresentationFieldShape>
	 */
	public function defaultFieldShapes(CollectionInterface $collection, object $source): array
	{
		$shapes = [];

		foreach ($collection->getFields() as $field) {
			$shapes[] = new RepresentationFieldShape(
				$field->getName(),
				$source,
				$field->getName(),
				writable: true,
			);
		}

		return $shapes;
	}

	/**
	 * Builds one field shape per primary-key field, rooted at the given source.
	 * Primary keys default to read-only so adoption can carry identity without
	 * marking them writable.
	 *
	 * @return list<RepresentationFieldShape>
	 */
	public function primaryKeyFieldShapes(CollectionInterface $collection, object $source, bool $writable = false): array
	{
		$shapes = [];

		foreach ($collection->getPrimaryKey() as $fieldName) {
			$shapes[] = new RepresentationFieldShape(
				$fieldName,
				$source,
				$fieldName,
				writable: $writable,
			);
		}

		return $shapes;
	}
}
