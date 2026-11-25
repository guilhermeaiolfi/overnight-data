<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Attribute;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class MapCollection
{
	/** @param class-string $itemClass */
	public function __construct(private string $itemClass)
	{
		if (! class_exists($itemClass)) {
			throw new InvalidArgumentException(sprintf('MapCollection item class "%s" does not exist.', $itemClass));
		}
	}

	/** @return class-string */
	public function getItemClass(): string
	{
		return $this->itemClass;
	}
}
