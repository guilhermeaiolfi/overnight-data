<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture\BracketedTraits {
	trait Item
	{
	}
}

namespace Tests\ON\Data\Fixture\BracketedFirst {
	use DateTimeImmutable as Item;
	use Tests\ON\Data\Fixture\BracketedTraits;

	final class Earlier
	{
		public function format(string $value): string
		{
			return "{$value}Suffix";
		}
	}

	final class WithTrait
	{
		use BracketedTraits\Item;
	}

	final class ListTarget
	{
		/** @var list<Item> */
		public array $items = [];
	}
}

namespace Tests\ON\Data\Fixture\BracketedSecond {
	use Tests\ON\Data\Fixture\AuthorDto as Item;

	final class ListTarget
	{
		/** @var list<Item> */
		public array $items = [];
	}
}
