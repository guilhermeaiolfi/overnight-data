<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use Countable;
use Iterator;

class PropertyContextFixture
{
	public string $name;

	public ?int $age = null;

	public mixed $mixedValue;

	public MixedValueObject $profile;

	public string|int $unionValue;

	public Countable&Iterator $intersectionValue;

	public $untypedValue;
}
