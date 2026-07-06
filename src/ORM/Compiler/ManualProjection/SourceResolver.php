<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\ManualProjection;

use ON\Data\ORM\Compiler\ProjectionSourceResolverInterface;
use ON\Data\ORM\Compiler\ProjectionSourceTarget;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RepresentationBinding;

final class SourceResolver implements ProjectionSourceResolverInterface
{
	public function resolve(object $source): ProjectionSourceTarget
	{
		if ($source instanceof PropertySource) {
			$record = $source->getTargetRecord();

			return new ProjectionSourceTarget($record->getCollection(), new RepresentationBinding(), $record);
		}

		if ($source instanceof RelationRef) {
			if ($source->getDefinition()->getCardinality() === 'many') {
				throw new StateException(sprintf(
					"Cannot select MANY relation source '%s' without first creating or identifying one concrete relation item.",
					implode('.', $source->getPath())
				));
			}

			return new ProjectionSourceTarget($source->getDefinition()->getCollection(), new RepresentationBinding());
		}

		throw new StateException(sprintf(
			"Cannot resolve manual projection source '%s' because it has no concrete record identity.",
			$source instanceof RelationRef
				? implode('.', $source->getPath())
				: $source::class,
		));
	}
}
