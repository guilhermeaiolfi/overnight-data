<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Metadata;

use ReflectionProperty;
use ReflectionType;

/** @internal */
final class PropertyMetadataReader
{
	private ?ListItemTypeExtractorInterface $listItemTypes = null;

	/** @param list<ListItemTypeExtractorInterface>|null $listItemTypeExtractors */
	public function __construct(private readonly ?array $listItemTypeExtractors = null)
	{
	}

	public function nativeType(ReflectionProperty $property): ?ReflectionType
	{
		return $property->getType();
	}

	/** @return class-string|null */
	public function collectionItemClass(ReflectionProperty $property): ?string
	{
		$this->listItemTypes ??= new CachedListItemTypeExtractor(
			new ListItemTypeExtractorChain($this->listItemTypeExtractors ?? [
				new MapCollectionAttributeExtractor(),
				PhpDocListItemTypeExtractor::forOperation(),
			]),
			maxEntries: null,
			cacheMisses: true,
		);

		return $this->listItemTypes->extract($property);
	}
}
