<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Field;

use InvalidArgumentException;
use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldTypeInterface;
use Stringable;

final class UrlFieldType implements FieldTypeInterface
{
	public static function getNames(): array
	{
		return ['url'];
	}

	public static function getStorageType(): string
	{
		return 'string';
	}

	public static function toPhp(mixed $value, FieldContext $field): mixed
	{
		return self::normalizeUrl($value, $field);
	}

	public static function fromPhp(mixed $value, FieldContext $field): mixed
	{
		return self::normalizeUrl($value, $field);
	}

	private static function normalizeUrl(mixed $value, FieldContext $field): ?string
	{
		if (! is_scalar($value) && ! $value instanceof Stringable) {
			throw new InvalidArgumentException(
				sprintf("Field '%s' expects a scalar or stringable URL value.", $field->getName()),
			);
		}

		$url = trim(str_replace('\\', '/', (string) $value));
		if ($url === '') {
			if ($field->isNullable()) {
				return null;
			}

			return '';
		}

		if (str_starts_with($url, '//')) {
			throw new InvalidArgumentException(
				sprintf("Field '%s' does not allow protocol-relative URLs.", $field->getName()),
			);
		}

		if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1) {
			$scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
			if (! in_array($scheme, ['http', 'https'], true)) {
				throw new InvalidArgumentException(
					sprintf("Field '%s' does not allow URL scheme '%s'.", $field->getName(), $scheme),
				);
			}

			return $url;
		}

		return '/' . ltrim($url, '/');
	}
}
