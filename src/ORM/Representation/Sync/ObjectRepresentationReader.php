<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use Closure;
use ON\Data\Schema\RepresentationRelationSchema;
use Throwable;

final readonly class ObjectRepresentationReader
{
	/**
	 * @internal
	 * @param Closure(object): array<array-key, mixed> $propertiesOf
	 */
	public function __construct(
		private object $source,
		private Closure $propertiesOf,
	) {
	}

	/** @return PathReadResult<mixed> */
	public function readPath(string $path): PathReadResult
	{
		$root = ($this->propertiesOf)($this->source);
		if (array_key_exists($path, $root)) {
			return PathReadResult::present($root[$path]);
		}

		return self::traversePath($root, $path, $this->propertiesOf);
	}

	/**
	 * @param array<string|int, mixed> $properties
	 * @param callable(object): array<string|int, mixed> $propertiesOf
	 * @return PathReadResult<mixed>
	 */
	private static function traversePath(array $properties, string $path, callable $propertiesOf): PathReadResult
	{
		$segments = explode('.', $path);
		$first = array_shift($segments);
		if (! array_key_exists($first, $properties)) {
			return self::missingSegment($path, $first);
		}

		$current = $properties[$first];
		foreach ($segments as $segment) {
			if (is_array($current)) {
				if (! ctype_digit($segment)) {
					return PathReadResult::invalid(sprintf(
						"Cannot read representation path '%s' because segment '%s' is not an array offset.",
						$path,
						$segment
					));
				}

				$offset = (int) $segment;
				if (! array_key_exists($offset, $current)) {
					return self::missingSegment($path, $segment);
				}

				$current = $current[$offset];

				continue;
			}

			if (is_object($current)) {
				$properties = $propertiesOf($current);
				if (! array_key_exists($segment, $properties)) {
					return self::missingSegment($path, $segment);
				}

				$current = $properties[$segment];

				continue;
			}

			return PathReadResult::invalid(sprintf(
				"Cannot read representation path '%s' because segment '%s' is not on an object or supported array path.",
				$path,
				$segment
			));
		}

		return PathReadResult::present($current);
	}

	/** @return PathReadResult<never> */
	private static function missingSegment(string $path, string $segment): PathReadResult
	{
		return PathReadResult::missing(sprintf("Cannot read representation path '%s' because segment '%s' is missing.", $path, $segment));
	}

	/**
	 * @param callable(non-empty-string): Throwable $error
	 * @return PathReadResult<list<object>>
	 */
	public function readItems(
		RepresentationRelationSchema $schema,
		callable $error,
	): PathReadResult {
		$result = $this->readPath($schema->getPath());
		if (! $result->isPresent()) {
			return $result;
		}

		$value = $result->getValue();
		if ($value === null) {
			return PathReadResult::present([]);
		}

		if (! is_iterable($value)) {
			throw $error(sprintf(
				"Representation relation path '%s' must contain an iterable value or null.",
				$schema->getPath()
			));
		}

		$items = [];
		foreach ($value as $item) {
			if (! is_object($item)) {
				throw $error(sprintf(
					"Representation relation path '%s' can only contain objects.",
					$schema->getPath()
				));
			}

			$items[] = $item;
		}

		return PathReadResult::present($items);
	}

	/**
	 * @param callable(non-empty-string): Throwable $error
	 * @return PathReadResult<object|null>
	 */
	public function readTarget(
		RepresentationRelationSchema $schema,
		callable $error,
	): PathReadResult {
		$result = $this->readPath($schema->getPath());
		if (! $result->isPresent()) {
			return $result;
		}

		$value = $result->getValue();
		if ($value === null || is_object($value)) {
			return PathReadResult::present($value);
		}

		throw $error(sprintf(
			"Representation relation path '%s' must contain an object value or null.",
			$schema->getPath()
		));
	}

	/**
	 * @param callable(non-empty-string): Throwable $error
	 * @return list<object>
	 */
	public function readRelatedObjects(RepresentationRelationSchema $schema, callable $error): array
	{
		if ($schema->isMany()) {
			return $this->readItems($schema, $error)->getValue();
		}

		$target = $this->readTarget($schema, $error)->getValue();

		return $target === null ? [] : [$target];
	}
}
