<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use function ON\Data\Mapper\map;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Representation\Schema\RepresentationRelationSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Session;
use stdClass;
use Throwable;

final class RepresentationReader
{
	/**
	 * @return array<string, mixed>
	 */
	public function read(object $representation, RepresentationSchema $schema): array
	{
		$bag = $this->mappedScalarBag($representation);
		$values = [];
		foreach ($schema->getFields() as $fieldSchema) {
			$path = $fieldSchema->getPath();
			$values[$path] = $this->readPath($bag, $path);
		}

		return $values;
	}

	/**
	 * Field-name values for clean baselines ({@see Session::identify()}, hydrate).
	 *
	 * Keeps names already present in $initialValues; soft-reads other schema fields.
	 *
	 * @param array<string, mixed> $initialValues
	 *
	 * @return array<string, mixed>
	 */
	public function baselineValues(
		object $representation,
		RepresentationSchema $schema,
		array $initialValues = [],
	): array {
		$bag = $this->mappedScalarBag($representation);
		$values = $initialValues;
		foreach ($schema->getFields() as $fieldSchema) {
			if (array_key_exists($fieldSchema->getFieldName(), $values)) {
				continue;
			}

			try {
				$values[$fieldSchema->getFieldName()] = $this->readPath(
					$bag,
					$fieldSchema->getPath(),
				);
			} catch (SyncException) {
			}
		}

		return $values;
	}

	/**
	 * Resolve a dotted path on an object (live representation or mapped scalar bag).
	 */
	public function readPath(object $source, string $path): mixed
	{
		$properties = get_object_vars($source);
		if (array_key_exists($path, $properties)) {
			return $properties[$path];
		}

		$current = $source;
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
		RepresentationRelationSchema $schema,
		callable $error,
	): array {
		$value = $this->readPath($representation, $schema->getPath());
		if ($value === null) {
			return [];
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

		return $items;
	}

	/**
	 * @param callable(non-empty-string): Throwable $error
	 */
	public function readTarget(
		object $representation,
		RepresentationRelationSchema $schema,
		callable $error,
	): ?object {
		$value = $this->readPath($representation, $schema->getPath());
		if ($value === null || is_object($value)) {
			return $value;
		}

		throw $error(sprintf(
			"Representation relation path '%s' must contain an object value or null.",
			$schema->getPath()
		));
	}

	private function mappedScalarBag(object $representation): object
	{
		/** @var object $bag */
		$bag = map($representation)->to(stdClass::class);

		return $bag;
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
