<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler;

/**
 * Shared final step of projection compilation: turns normalized field shapes and
 * template scalar declarations into RepresentationFieldBinding entries.
 *
 * Exists so SelectQuery and manual projection compilers share one place that
 * resolves sources, chooses template vs concrete RecordFieldRef, and applies
 * writability / skip-when-missing flags.
 */
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\State\RecordFieldRef;
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
		bool $skipWhenMissing = false,
	): RepresentationBinding {
		$binding = new RepresentationBinding();
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
			$record = $resolved->getRecordState();
			$recordField = $record === null
				? RecordFieldRef::template($resolved->getCollection(), $shape->getFieldName())
				: RecordFieldRef::forState($record, $shape->getFieldName());

			$binding->addField(new RepresentationFieldBinding(
				$shape->getPublicPath(),
				$recordField,
				writable: $shape->isWritable(),
				skipWhenMissing: $skipWhenMissing,
			));
		}
	}

	public function addDefaultCollectionFields(RepresentationBinding $binding, CollectionInterface $collection): void
	{
		foreach ($collection->getFields() as $field) {
			$this->addTemplateField(
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
			if ($this->hasTemplateFieldFor($binding, $collection, $fieldName)) {
				continue;
			}

			$this->addTemplateField(
				$binding,
				$collection,
				$fieldName,
				$fieldName,
				writable: false,
			);
		}
	}

	public function addTemplateField(
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
			RecordFieldRef::template($collection, $fieldName),
			writable: $writable,
			skipWhenMissing: $skipWhenMissing,
		));
	}

	public function hasTemplateFieldFor(
		RepresentationBinding $binding,
		CollectionInterface $collection,
		string $fieldName,
	): bool {
		foreach ($binding->getFields() as $fieldBinding) {
			if (
				$fieldBinding->getField()->getCollectionName() === $collection->getName()
				&& $fieldBinding->getField()->getFieldName() === $fieldName
			) {
				return true;
			}
		}

		return false;
	}
}
