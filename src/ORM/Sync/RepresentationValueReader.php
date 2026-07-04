<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RepresentationBinding;

final class RepresentationValueReader
{
	/**
	 * @return array<string, mixed>
	 */
	public function read(object $representation, RepresentationBinding $binding): array
	{
		$values = [];
		foreach ($binding->getFields() as $fieldBinding) {
			$path = $fieldBinding->getPath();
			$values[$path] = $this->readPath($representation, $path);
		}

		return $values;
	}

	public function readPath(object $representation, string $path): mixed
	{
		$current = $representation;
		foreach (explode('.', $path) as $segment) {
			$current = $this->readSegment($current, $segment, $path);
		}

		return $current;
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
