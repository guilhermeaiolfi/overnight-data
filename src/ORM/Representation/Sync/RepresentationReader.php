<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use function ON\Data\Mapper\map;
use ON\Data\ORM\Session;
use ON\Data\Schema\RepresentationRelationSchema;
use ON\Data\Schema\RepresentationSchema;
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
			$values[$path] = $this->readPath($bag, $path)->requireValue();
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

			$result = $this->readPath($bag, $fieldSchema->getPath());
			if ($result->isPresent()) {
				$values[$fieldSchema->getFieldName()] = $result->requireValue();
			}
		}

		return $values;
	}

	/**
	 * Resolve a dotted path on an object (live representation or mapped scalar bag).
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

	private function mappedScalarBag(object $representation): object
	{
		/** @var object $bag */
		$bag = map($representation)->to(stdClass::class);

		return $bag;
	}
}
