<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Support;

use ON\Data\Mapper\Metadata\PropertyMetadataReader;
use ReflectionProperty;

/** Resolves explicit collection item types with PHPDoc as a fallback. */
final class DocblockListTargetResolver
{
	private readonly PropertyMetadataReader $propertyMetadata;

	public function __construct()
	{
		$this->propertyMetadata = new PropertyMetadataReader();
	}

	/** @return class-string|null */
	public function resolve(ReflectionProperty $property): ?string
	{
		return $this->propertyMetadata->collectionItemClass($property);
	}

	/** @internal */
	public function getPropertyMetadata(): PropertyMetadataReader
	{
		return $this->propertyMetadata;
	}
}
