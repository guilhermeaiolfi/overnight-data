<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\Schema\RepresentationRelationSchema;
use Throwable;

final class RepresentationReader
{
	/**
	 * Read initialized public properties directly from the live representation.
	 * A literal dotted property takes precedence over traversal.
	 *
	 * @return PathReadResult<mixed>
	 */
	public function readPath(object $source, string $path): PathReadResult
	{
		$properties = get_object_vars($source);
		if (array_key_exists($path, $properties)) {
			return PathReadResult::present($properties[$path]);
		}

		$current = $source;
		foreach (explode('.', $path) as $segment) {
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
					return PathReadResult::missing(sprintf("Cannot read representation path '%s' because segment '%s' is missing.", $path, $segment));
				}

				$current = $current[$offset];

				continue;
			}

			if (is_object($current)) {
				$properties = get_object_vars($current);
				if (! array_key_exists($segment, $properties)) {
					return PathReadResult::missing(sprintf("Cannot read representation path '%s' because segment '%s' is missing.", $path, $segment));
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

	/**
	 * @param callable(non-empty-string): Throwable $error
	 * @return PathReadResult<list<object>>
	 */
	public function readItems(
		object $representation,
		RepresentationRelationSchema $schema,
		callable $error,
	): PathReadResult {
		$result = $this->readPath($representation, $schema->getPath());
		if (! $result->isPresent()) {
			return $result;
		}

		$value = $result->requireValue();
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
		object $representation,
		RepresentationRelationSchema $schema,
		callable $error,
	): PathReadResult {
		$result = $this->readPath($representation, $schema->getPath());
		if (! $result->isPresent()) {
			return $result;
		}

		$value = $result->requireValue();
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
	public function readRelatedObjects(object $representation, RepresentationRelationSchema $schema, callable $error): array
	{
		if ($schema->isMany()) {
			return $this->readItems($representation, $schema, $error)->requireValue();
		}

		$target = $this->readTarget($representation, $schema, $error)->requireValue();

		return $target === null ? [] : [$target];
	}
}
