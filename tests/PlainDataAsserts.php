<?php

declare(strict_types=1);

namespace Tests\ON\Data;

use Closure;
use PHPUnit\Framework\Assert;

trait PlainDataAsserts
{
	protected static function assertPlainData(mixed $value, string $path = 'root'): void
	{
		if (is_array($value)) {
			foreach ($value as $key => $nestedValue) {
				self::assertPlainData($nestedValue, sprintf('%s[%s]', $path, (string) $key));
			}

			return;
		}

		if ($value instanceof Closure) {
			Assert::fail(sprintf('Closure found at %s', $path));
		}

		Assert::assertFalse(is_object($value), sprintf('Object found at %s', $path));
		Assert::assertFalse(is_resource($value), sprintf('Resource found at %s', $path));
		Assert::assertTrue(
			is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null,
			sprintf('Unsupported value type at %s', $path),
		);
	}
}
