<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Metadata;

use ReflectionProperty;

interface ListItemTypeExtractorInterface
{
	/** @return class-string|null */
	public function extract(ReflectionProperty $property): ?string;
}
