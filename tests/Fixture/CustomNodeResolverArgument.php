<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

final readonly class CustomNodeResolverArgument
{
	public function __construct(
		public string $fieldName,
		public mixed $target,
		public bool $collection = false,
	) {
	}
}
