<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler;

/**
 * Shared final step of projection compilation: turns normalized field shapes and
 * scalar field declarations into structural RepresentationFieldBinding entries.
 *
 * Exists so SelectQuery and manual projection compilers share one place that
 * resolves sources and applies writability / skip-when-missing flags.
 */
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;

final class ProjectionBindingAssembler
{
	/**
	 * @param list<ProjectionFieldShape> $fieldShapes
	 */
	public function assemble(
		array $fieldShapes,
		ProjectionSourceResolverInterface $resolver,
		CollectionInterface $collection,
		bool $skipWhenMissing = false,
	): RepresentationBinding {
		$binding = new RepresentationBinding($collection);
		$this->assembleInto($binding, $fieldShapes, $resolver, $skipWhenMissing);

		return $binding;
	}

	/**
	 * @param list<ProjectionFieldShape> $fieldShapes
	 */
	public function assembleInto(
		RepresentationBinding $binding,
		array $fieldShapes,
		ProjectionSourceResolverInterface $resolver,
		bool $skipWhenMissing = false,
	): void {
		foreach ($fieldShapes as $shape) {
			if ($binding->hasField($shape->getPublicPath())) {
				continue;
			}

			$resolved = $resolver->resolve($shape->getSource());
			$binding->addField(new RepresentationFieldBinding(
				$shape->getPublicPath(),
				$resolved->getCollection(),
				$shape->getFieldName(),
				writable: $shape->isWritable(),
				skipWhenMissing: $skipWhenMissing,
				sourcePath: $resolved->getSourcePath(),
			));
		}
	}

	public function addDefaultCollectionFields(RepresentationBinding $binding, CollectionInterface $collection): void
	{
		foreach ($collection->getFields() as $field) {
			$this->addField(
				$binding,
				$collection,
				$field->getName(),
				$field->getName(),
				writable: true,
			);
		}
	}

	public function addPrimaryKeyFields(RepresentationBinding $binding, CollectionInterface $collection): void
	{
		foreach ($collection->getPrimaryKey() as $fieldName) {
			if ($this->hasFieldForSource($binding, [], $fieldName)) {
				continue;
			}

			$this->addField(
				$binding,
				$collection,
				$fieldName,
				$fieldName,
				writable: false,
			);
		}
	}

	public function addField(
		RepresentationBinding $binding,
		CollectionInterface $collection,
		string $publicPath,
		string $fieldName,
		bool $writable = true,
		bool $skipWhenMissing = false,
	): void {
		if ($binding->hasField($publicPath)) {
			return;
		}

		$binding->addField(new RepresentationFieldBinding(
			$publicPath,
			$collection,
			$fieldName,
			writable: $writable,
			skipWhenMissing: $skipWhenMissing,
		));
	}

	/**
	 * @param list<string> $sourcePath
	 */
	public function hasFieldForSource(
		RepresentationBinding $binding,
		array $sourcePath,
		string $fieldName,
	): bool {
		return $binding->hasFieldForSource($sourcePath, $fieldName);
	}
}
