<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Metadata;

use ON\Data\Mapper\Attribute\MapCollection;
use ReflectionProperty;

final class MapCollectionAttributeExtractor implements ListItemTypeExtractorInterface
{
	public function extract(ReflectionProperty $property): ?string
	{
		$attribute = $property->getAttributes(MapCollection::class)[0] ?? null;

		return $attribute?->newInstance()->getItemClass();
	}
}
