<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Attribute;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class MapFrom
{
	public function __construct(
		private string $name,
	) {
		if ($name === '') {
			throw new InvalidArgumentException('MapFrom name cannot be empty.');
		}
	}

	public function getName(): string
	{
		return $this->name;
	}
}
