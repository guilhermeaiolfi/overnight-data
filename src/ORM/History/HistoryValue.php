<?php

declare(strict_types=1);

namespace ON\Data\ORM\History;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use UnitEnum;

/** @internal */
final class HistoryValue
{
	/**
	 * @param array<string, mixed> $values
	 * @return array{array<string, mixed>, list<string>}
	 */
	public static function snapshot(array $values): array
	{
		$copy = [];
		$unavailable = [];
		foreach ($values as $field => $value) {
			$supported = true;
			$snapshot = self::copy($value, $supported, 0);
			if ($supported) {
				$copy[$field] = $snapshot;
			} else {
				$unavailable[] = $field;
			}
		}

		return [$copy, $unavailable];
	}

	private static function copy(mixed $value, bool &$supported, int $depth): mixed
	{
		if ($value === null || is_scalar($value) || $value instanceof UnitEnum) {
			return $value;
		}
		if ($value instanceof DateTimeInterface) {
			return DateTimeImmutable::createFromInterface($value);
		}
		if (is_array($value) && $depth < 64) {
			$copy = [];
			foreach ($value as $key => $item) {
				$copy[$key] = self::copy($item, $supported, $depth + 1);
				if (! $supported) {
					return null;
				}
			}

			return $copy;
		}

		$supported = false;

		return null;
	}

	public static function forArray(mixed $value): mixed
	{
		return match (true) {
			is_array($value) => self::exportValues($value),
			$value instanceof DateTimeImmutable => $value->format('Y-m-d\TH:i:s.uP'),
			$value instanceof BackedEnum => self::forArray($value->value),
			$value instanceof UnitEnum => self::forArray($value->name),
			is_string($value) && preg_match('//u', $value) !== 1 => ['base64' => base64_encode($value)],
			is_float($value) && ! is_finite($value) => (string) $value,
			default => $value,
		};
	}

	/** @param array<array-key, mixed> $values */
	private static function exportValues(array $values): array
	{
		foreach ($values as $key => $_) {
			if (is_string($key) && preg_match('//u', $key) !== 1) {
				$entries = [];
				foreach ($values as $field => $item) {
					$entries[] = [self::forArray($field), self::forArray($item)];
				}

				return ['entries' => $entries];
			}
		}

		return array_map(self::forArray(...), $values);
	}
}
