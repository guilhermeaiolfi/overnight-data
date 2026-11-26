<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Metadata;

use ReflectionProperty;

/** @internal */
final class ListItemTypeExtractorChain implements ListItemTypeExtractorInterface
{
	/** @param list<ListItemTypeExtractorInterface>|null $extractors */
	private function __construct(private ?array $extractors)
	{
	}

	/** @param list<ListItemTypeExtractorInterface>|null $extractors */
	public static function forOperation(?array $extractors = null): ListItemTypeExtractorInterface
	{
		return new CachedListItemTypeExtractor(new self($extractors), maxEntries: null, cacheMisses: true);
	}

	public function extract(ReflectionProperty $property): ?string
	{
		$this->extractors ??= [new MapCollectionAttributeExtractor(), PhpDocListItemTypeExtractor::forOperation()];
		foreach ($this->extractors as $extractor) {
			$type = $extractor->extract($property);
			if ($type !== null) {
				return $type;
			}
		}

		return null;
	}
}
