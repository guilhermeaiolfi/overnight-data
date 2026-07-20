<?php

declare(strict_types=1);

namespace ON\Data\Definition\Field\Generator;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;

/**
 * PHP-owned "now" timestamp as {@see DateTimeImmutable}.
 *
 * Optional `$arg` / constructor value is a timezone name (e.g. "UTC")
 * or a {@see DateTimeZone}. Omit for the default timezone.
 */
final class NowGenerator implements PhpFieldGeneratorInterface, GeneratorDefinitionArgInterface
{
	private readonly ?DateTimeZone $timezone;

	private readonly ?string $timezoneName;

	public function __construct(null|string|DateTimeZone $timezone = null)
	{
		if ($timezone instanceof DateTimeZone) {
			$this->timezone = $timezone;
			$this->timezoneName = $timezone->getName();

			return;
		}

		if ($timezone === null || $timezone === '') {
			$this->timezone = null;
			$this->timezoneName = null;

			return;
		}

		try {
			$this->timezone = new DateTimeZone($timezone);
		} catch (Exception $exception) {
			throw new InvalidArgumentException(sprintf(
				'NowGenerator timezone "%s" is invalid.',
				$timezone,
			), 0, $exception);
		}

		$this->timezoneName = $timezone;
	}

	public function generate(GenerationContext $context): mixed
	{
		if ($this->timezone === null) {
			return new DateTimeImmutable('now');
		}

		return new DateTimeImmutable('now', $this->timezone);
	}

	public function getDefinitionArg(): mixed
	{
		return $this->timezoneName;
	}
}
