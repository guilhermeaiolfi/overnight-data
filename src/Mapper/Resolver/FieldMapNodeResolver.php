<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;

final class FieldMapNodeResolver implements NodeResolverInterface
{
	public function resolve(
		MappingNode $node,
		MappingRuntime $runtime,
	): ?LeafNodeResolutionInterface {
		$fieldMap = $node->getContext()->getFieldMap();
		if ($fieldMap === null) {
			return null;
		}

		$name = $node->getName();
		if (! is_string($name)) {
			return null;
		}

		return $fieldMap->getField($node->getPath(), $name);
	}
}
