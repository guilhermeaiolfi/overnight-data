<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler;

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

			$target = $resolver->resolve($shape->getSource());
			$record = $target->getRecordState();
			$recordField = $record === null
				? RecordFieldRef::template($target->getCollection(), $shape->getFieldName())
				: RecordFieldRef::forState($record, $shape->getFieldName());

			$binding->addField(new RepresentationFieldBinding(
				$shape->getPublicPath(),
				$recordField,
				writable: $shape->isWritable(),
				skipWhenMissing: $skipWhenMissing,
			));
		}
	}
}
