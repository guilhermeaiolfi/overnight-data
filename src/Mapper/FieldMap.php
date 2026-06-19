<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use InvalidArgumentException;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;

final class FieldMap
{
	/**
	 * @param array<
	 *     non-empty-string,
	 *     array{
	 *         type: non-empty-string,
	 *         nullable: bool
	 *     }
	 * > $fields
	 */
	private function __construct(
		private readonly array $fields,
	) {
	}

	/**
	 * @param array<
	 *     non-empty-string,
	 *     non-empty-string|array{
	 *         type: non-empty-string,
	 *         nullable?: bool
	 *     }
	 * > $fields
	 */
	public static function fromArray(array $fields): self
	{
		$validated = [];

		foreach ($fields as $path => $entry) {
			if (! is_string($path)) {
				throw new InvalidArgumentException('FieldMap paths must be strings.');
			}

			$validated[self::validateConfiguredPath($path)] = self::validateEntry($path, $entry);
		}

		return new self($validated);
	}

	/**
	 * @return array<
	 *     non-empty-string,
	 *     array{
	 *         type: non-empty-string,
	 *         nullable: bool
	 *     }
	 * >
	 */
	public function getFields(): array
	{
		return $this->fields;
	}

	public function getField(string $path, string $name): ?LeafNodeResolutionInterface
	{
		$entry = $this->fields[$this->canonicalizeRuntimePath($path)] ?? null;
		if ($entry === null) {
			return null;
		}

		return LeafNodeResolution::named($name, $entry['type'], $entry['nullable']);
	}

	/**
	 * @param non-empty-string $path
	 * @param mixed $entry
	 *
	 * @return array{type: non-empty-string, nullable: bool}
	 */
	private static function validateEntry(string $path, mixed $entry): array
	{
		if (is_string($entry)) {
			if ($entry === '') {
				throw new InvalidArgumentException(sprintf("FieldMap path '%s' must declare a non-empty type.", $path));
			}

			return [
				'type' => $entry,
				'nullable' => false,
			];
		}

		if (! is_array($entry)) {
			throw new InvalidArgumentException(sprintf("FieldMap path '%s' must use a string or metadata array entry.", $path));
		}

		$allowedKeys = ['type' => true, 'nullable' => true];
		foreach ($entry as $key => $_value) {
			if (! isset($allowedKeys[$key])) {
				throw new InvalidArgumentException(sprintf("FieldMap path '%s' contains unknown key '%s'.", $path, $key));
			}
		}

		if (! array_key_exists('type', $entry) || ! is_string($entry['type']) || $entry['type'] === '') {
			throw new InvalidArgumentException(sprintf("FieldMap path '%s' must declare a non-empty string type.", $path));
		}

		$nullable = $entry['nullable'] ?? false;
		if (! is_bool($nullable)) {
			throw new InvalidArgumentException(sprintf("FieldMap path '%s' must declare nullable as a boolean.", $path));
		}

		return [
			'type' => $entry['type'],
			'nullable' => $nullable,
		];
	}

	private static function validateConfiguredPath(string $path): string
	{
		if (trim($path) === '') {
			throw new InvalidArgumentException('FieldMap paths must be non-empty strings.');
		}

		if (str_starts_with($path, '.') || str_ends_with($path, '.') || str_contains($path, '..')) {
			throw new InvalidArgumentException(sprintf("FieldMap path '%s' must use non-empty dotted segments.", $path));
		}

		$segments = explode('.', $path);
		foreach ($segments as $segment) {
			if ($segment === '') {
				throw new InvalidArgumentException(sprintf("FieldMap path '%s' must use non-empty dotted segments.", $path));
			}

			if (preg_match('/^\d+$/', $segment) === 1) {
				throw new InvalidArgumentException(sprintf("FieldMap path '%s' cannot contain numeric segments.", $path));
			}

			if (strpbrk($segment, '*?[]{}()\\') !== false) {
				throw new InvalidArgumentException(sprintf("FieldMap path '%s' cannot contain wildcard syntax.", $path));
			}
		}

		return $path;
	}

	private function canonicalizeRuntimePath(string $path): string
	{
		if ($path === '') {
			return '';
		}

		$segments = explode('.', $path);
		$segments = array_values(array_filter(
			$segments,
			static fn (string $segment): bool => $segment !== '' && preg_match('/^\d+$/', $segment) !== 1,
		));

		return implode('.', $segments);
	}
}
