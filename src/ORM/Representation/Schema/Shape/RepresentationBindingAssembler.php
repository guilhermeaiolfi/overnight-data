<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Shape;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\Definition\Collection\CollectionInterface;
/**
 * Shared structural binding creation for projection compilation: turns normalized
 * RepresentationFieldShape values into RepresentationFieldSchema entries, and offers
 * default/primary-key shape factories so root, default, and PK fields flow
 * through the same shape path as explicit selections.
 *
 * Exists so SelectQuery and manual projection compilers share one query-agnostic,
 * manual-projection-agnostic place that resolves sources and applies writability
 * / skip-when-missing flags.
 */
final class RepresentationBindingAssembler
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
		$binding = new RepresentationSchema($collection);
		$this->assembleInto($binding, $fieldShapes, $resolver, $skipWhenMissing);

		return $binding;
	}

	/**
	 * @param list<RepresentationFieldShape> $fieldShapes
	 */
	public function assembleInto(
		RepresentationSchema $binding,
		array $fieldShapes,
		RepresentationSourceResolverInterface $resolver,
		bool $skipWhenMissing = false,
	): void {
		foreach ($fieldShapes as $shape) {
			if ($binding->hasField($shape->getPublicPath())) {
				continue;
			}

			$resolved = $resolver->resolve($shape->getSource());
			$binding->addField(new RepresentationFieldSchema(
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
	 * collection so default field bindings flow through the same shape path as
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
