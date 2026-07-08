<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\ManualProjection;

/**
 * Resolves manual projection field-shape sources (PropertySource, RelationRef)
 * to collection and source path for binding assembly.
 *
 * Exists as the manual ProjectionSourceResolverInterface implementation;
 * enforces that MANY relations and unresolved sources cannot be compiled alone.
 */
use ON\Data\ORM\Compiler\ProjectionSourceResolverInterface;
use ON\Data\ORM\Compiler\ResolvedProjectionSource;
use ON\Data\ORM\Exception\StateException;

final class SourceResolver implements ProjectionSourceResolverInterface
{
	public function resolve(object $source): ResolvedProjectionSource
	{
		if ($source instanceof PropertySource) {
			$record = $source->getTargetRecord();

			return new ResolvedProjectionSource($record->getCollection(), $source->getRelationPath());
		}

		if ($source instanceof RelationRef) {
			if ($source->getDefinition()->getCardinality()->isMany()) {
				throw new StateException(sprintf(
					"Cannot select MANY relation source '%s' without first creating or identifying one concrete relation item.",
					implode('.', $source->getPath())
				));
			}

			return new ResolvedProjectionSource($source->getDefinition()->getCollection(), sourcePath: $source->getPath());
		}

		throw new StateException(sprintf(
			"Cannot resolve manual projection source '%s' because it has no concrete record identity.",
			$source instanceof RelationRef
				? implode('.', $source->getPath())
				: $source::class,
		));
	}
}
