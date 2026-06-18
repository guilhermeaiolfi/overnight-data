<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\MappingNode;

final class FieldMapFieldResolver implements FieldResolverInterface
{
	public function resolve(MappingNode $node): ?FieldContext
	{
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
