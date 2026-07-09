<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Manual;

use ON\Data\ORM\Representation\Schema\Shape\RepresentationSourceResolverInterface;
use ON\Data\ORM\Representation\Schema\Shape\ResolvedRepresentationSource;
use ON\Data\ORM\Exception\StateException;
/**
 * Resolves manual projection field-shape sources (ManualRepresentationSourceInterface, RelationRef)
 * to collection and source path for schema assembly.
 *
 * Exists as the manual RepresentationSourceResolverInterface implementation;
 * enforces that MANY relations and unresolved sources cannot be compiled alone.
 */
final class ManualRepresentationSourceResolver implements RepresentationSourceResolverInterface
{
	public function resolve(object $source): ResolvedRepresentationSource
	{
		if ($source instanceof ManualRepresentationSourceInterface) {
			$record = $source->getTargetRecord();

			return new ResolvedRepresentationSource($record->getCollection(), $source->getRelationPath());
		}

		if ($source instanceof RelationRef) {
			if ($source->getDefinition()->getCardinality()->isMany()) {
				throw new StateException(sprintf(
					"Cannot select MANY relation source '%s' without first creating or identifying one concrete relation item.",
					implode('.', $source->getPath())
				));
			}

			return new ResolvedRepresentationSource($source->getDefinition()->getCollection(), sourcePath: $source->getPath());
		}

		throw new StateException(sprintf(
			"Cannot resolve manual projection source '%s' because it has no concrete record identity.",
			$source instanceof RelationRef
				? implode('.', $source->getPath())
				: $source::class,
		));
	}
}
