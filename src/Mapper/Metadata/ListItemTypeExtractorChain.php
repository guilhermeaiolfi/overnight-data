<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Metadata;

use ReflectionProperty;

/** @internal */
final class ListItemTypeExtractorChain implements ListItemTypeExtractorInterface
{
	/** @param list<ListItemTypeExtractorInterface> $extractors */
	public function __construct(private readonly array $extractors)
	{
	}

	public function extract(ReflectionProperty $property): ?string
	{
		foreach ($this->extractors as $extractor) {
			$type = $extractor->extract($property);
			if ($type !== null) {
				return $type;
			}
		}

		return null;
	}
}
