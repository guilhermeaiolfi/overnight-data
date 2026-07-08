<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RepresentationSchema;
use ON\Data\ORM\State\RepresentationRelationSchema;
use Throwable;

final class RepresentationReader
{
	/**
	 * @return array<string, mixed>
	 */
	public function read(object $representation, RepresentationSchema $binding): array
	{
		$values = [];
		foreach ($binding->getFields() as $fieldSchema) {
			$path = $fieldSchema->getPath();
			$values[$path] = $this->readPath($representation, $path);
		}

		return $values;
	}

	public function readPath(object $representation, string $path): mixed
	{
		$properties = get_object_vars($representation);
		if (array_key_exists($path, $properties)) {
			return $properties[$path];
		}

		$current = $representation;
		foreach (explode('.', $path) as $segment) {
			$current = $this->readSegment($current, $segment, $path);
		}

		return $current;
	}

	/**
	 * @param callable(non-empty-string): Throwable $error
	 *
	 * @return list<object>
	 */
	public function readItems(
		object $representation,
		RepresentationRelationSchema $binding,
		callable $error,
	): array {
		$value = $this->readPath($representation, $binding->getPath());
		if ($value === null) {
			return [];
		}

		if (! is_iterable($value)) {
			throw $error(sprintf(
				"Representation relation path '%s' must contain an iterable value or null.",
				$binding->getPath()
			));
		}

		$items = [];
		foreach ($value as $item) {
			if (! is_object($item)) {
				throw $error(sprintf(
					"Representation relation path '%s' can only contain objects.",
					$binding->getPath()
				));
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * @param callable(non-empty-string): Throwable $error
	 */
	public function readTarget(
		object $representation,
		RepresentationRelationSchema $binding,
		callable $error,
	): ?object {
		$value = $this->readPath($representation, $binding->getPath());
		if ($value === null || is_object($value)) {
			return $value;
		}

		throw $error(sprintf(
			"Representation relation path '%s' must contain an object value or null.",
			$binding->getPath()
		));
	}

	private function readSegment(mixed $current, string $segment, string $path): mixed
	{
		if (is_array($current)) {
			return $this->readArraySegment($current, $segment, $path);
		}

		if (is_object($current)) {
			return $this->readObjectSegment($current, $segment, $path);
		}

		throw new SyncException(sprintf(
			"Cannot read representation path '%s' because segment '%s' is not on an object or supported array path.",
			$path,
			$segment
		));
	}

	/**
	 * @param array<mixed> $current
	 */
	private function readArraySegment(array $current, string $segment, string $path): mixed
	{
		if (! ctype_digit($segment)) {
			throw new SyncException(sprintf(
				"Cannot read representation path '%s' because segment '%s' is not an array offset.",
				$path,
				$segment
			));
		}

		$offset = (int) $segment;
		if (! array_key_exists($offset, $current)) {
			throw new SyncException(sprintf("Cannot read representation path '%s' because segment '%s' is missing.", $path, $segment));
		}

		return $current[$offset];
	}

	private function readObjectSegment(object $current, string $segment, string $path): mixed
	{
		$properties = get_object_vars($current);
		if (! array_key_exists($segment, $properties)) {
			throw new SyncException(sprintf("Cannot read representation path '%s' because segment '%s' is missing.", $path, $segment));
		}

		return $properties[$segment];
	}
}
