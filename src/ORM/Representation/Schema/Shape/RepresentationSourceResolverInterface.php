<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Shape;

/**
 * Maps a projection field-shape source (query root, relation ref, manual target)
 * to collection and record identity for schema assembly.
 *
 * Exists to keep RepresentationSchemaAssembler source-agnostic; each compiler
 * supplies its own resolver implementation.
 */
interface RepresentationSourceResolverInterface
{
	public function resolve(object $source): ResolvedRepresentationSource;
}
