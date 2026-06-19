<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Mapper;

final readonly class ArrayMapperOptions
{
	public function __construct(
		private bool $expandDottedKeys = true,
	) {
	}

	public function getExpandDottedKeys(): bool
	{
		return $this->expandDottedKeys;
	}
}
