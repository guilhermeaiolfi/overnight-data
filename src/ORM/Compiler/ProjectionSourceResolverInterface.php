<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler;

/**
 * Maps a projection field-shape source (query root, relation ref, manual target)
 * to collection and record identity for binding assembly.
 *
 * Exists to keep ProjectionBindingAssembler source-agnostic; each compiler
 * supplies its own resolver implementation.
 */
interface ProjectionSourceResolverInterface
{
	public function resolve(object $source): ProjectionSourceTarget;
}
