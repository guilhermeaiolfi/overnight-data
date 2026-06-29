<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

final class ReadonlyPromotedAuthorDto
{
	public function __construct(
		public readonly int $id,
		public readonly string $name = '',
		public readonly bool $active = false,
	) {
	}
}
