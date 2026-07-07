<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\SelectQuery;

/**
 * Resolves every projection source to one fixed collection rooted at the binding
 * itself (empty source path).
 *
 * Exists for nested relation bindings: their scalar fields live at the related
 * binding root, so they must resolve to the target collection with an empty
 * source path rather than the outer query's relation path. This lets nested
 * relation fields flow through the same ProjectionFieldShape + assembler path as
 * root fields.
 */
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Compiler\ProjectionSourceResolverInterface;
use ON\Data\ORM\Compiler\ResolvedProjectionSource;

final class RootSourceResolver implements ProjectionSourceResolverInterface
{
	public function __construct(
		private CollectionInterface $collection,
	) {
	}

	public function resolve(object $source): ResolvedProjectionSource
	{
		return new ResolvedProjectionSource($this->collection, sourcePath: []);
	}
}
