<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use Countable;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Iterator;

class PropertyContextFixture
{
	public string $name;

	public ?int $age = null;

	public mixed $mixedValue;

	public MixedValueObject $profile;

	public string|int $unionValue;

	public Countable&Iterator $intersectionValue;

	public StatusEnum $status;

	public ?StatusEnum $nullableStatus = null;

	public IntStatusEnum $intStatus;

	public UnitStatusEnum $unitStatus;

	public DateTimeImmutable $publishedAt;

	public DateTimeInterface $publishedAtInterface;

	public ?DateTimeImmutable $nullablePublishedAt = null;

	public DateTime $mutablePublishedAt;

	public $untypedValue;
}
