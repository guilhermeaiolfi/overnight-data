<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use Closure;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Exception\MappingException;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Mapping;
use ON\Data\Schema\RepresentationRelationSchema;
use Throwable;
use WeakMap;

final class RepresentationReader
{
	public function __construct(private readonly ?ConversionGateway $gateway = null)
	{
	}

	/** @return PathReadResult<mixed> */
	public function readPath(object $source, string $path): PathReadResult
	{
		return ($this->readPass($source))($path);
	}

	/**
	 * Read paths against one representation, mapping each visited object once.
	 *
	 * @return Closure(string): PathReadResult<mixed>
	 */
	public function readPass(object $source): Closure
	{
		$gateway = $this->gateway ?? Mapping::getDefaultGateway();
		$mapProperties = static function (object $object) use ($gateway): array {
			$result = map($object, gateway: $gateway)->shallow()->to([]);
			if (! is_array($result)) {
				throw new MappingException('Reading representation properties requires an array mapping result.');
			}

			return $result;
		};
		$root = null;
		$readNested = null;
		/** @var WeakMap<object, array<string|int, mixed>>|null $properties */
		$properties = null;

		return static function (string $path) use ($source, $mapProperties, &$root, &$readNested, &$properties): PathReadResult {
			$root ??= $mapProperties($source);
			if (array_key_exists($path, $root)) {
				return PathReadResult::present($root[$path]);
			}

			$readNested ??= static function (object $object) use ($source, $mapProperties, $root, &$properties): array {
				if ($object === $source) {
					return $root;
				}

				$properties ??= new WeakMap();

				return $properties[$object] ??= $mapProperties($object);
			};

			return self::traversePath($root, $path, $readNested);
		};
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
