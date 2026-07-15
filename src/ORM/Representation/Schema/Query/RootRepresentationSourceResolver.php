<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Query;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationFieldShape;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationSourceResolverInterface;
use ON\Data\ORM\Representation\Schema\Shape\ResolvedRepresentationSource;

/**
 * Resolves every projection source to one fixed collection rooted at the schema
 * itself (empty source path).
 *
 * Exists for nested relation schemas: their scalar fields live at the related
 * schema root, so they must resolve to the target collection with an empty
 * source path rather than the outer query's relation path. This lets nested
 * relation fields flow through the same RepresentationFieldShape + assembler path as
 * root fields.
 */
final class RootRepresentationSourceResolver implements RepresentationSourceResolverInterface
{
	public function __construct(
		private CollectionInterface $collection,
	) {
	}

	public function resolve(object $source): ResolvedRepresentationSource
	{
		return new ResolvedRepresentationSource($this->collection, sourcePath: []);
	}
}
