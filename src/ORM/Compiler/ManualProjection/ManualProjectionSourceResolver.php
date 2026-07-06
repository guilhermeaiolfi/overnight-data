<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\ManualProjection;

use ON\Data\ORM\Compiler\ProjectionSourceResolver;
use ON\Data\ORM\Compiler\ProjectionSourceTarget;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\ManualProjection\ManualProjectionPropertySource;
use ON\Data\ORM\ManualProjection\ManualProjectionRelationRef;
use ON\Data\ORM\State\RepresentationBinding;

final class ManualProjectionSourceResolver implements ProjectionSourceResolver
{
	public function resolve(object $source): ProjectionSourceTarget
	{
		if ($source instanceof ManualProjectionPropertySource) {
			$record = $source->getTargetRecord();

			return new ProjectionSourceTarget($record->getCollection(), new RepresentationBinding(), $record);
		}

		if ($source instanceof ManualProjectionRelationRef) {
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
			$source instanceof ManualProjectionRelationRef
				? implode('.', $source->getPath())
				: $source::class,
		));
	}
}
