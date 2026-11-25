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
	/** @var WeakMap<object, array<array-key, mixed>> */
	private WeakMap $properties;

	public function __construct(private readonly ?ConversionGateway $gateway = null)
	{
	}

	/**
	 * A snapshot scoped to this traversal. Synchronizers using the same gateway share property reads.
	 * @param WeakMap<ConversionGateway, WeakMap<object, array<array-key, mixed>>>|null $snapshots
	 */
	public function forTraversal(?WeakMap $snapshots = null): self
	{
		$gateway = $this->gateway ?? Mapping::getDefaultGateway();
		$reader = new self($gateway);
		$reader->properties = $snapshots === null ? new WeakMap() : ($snapshots[$gateway] ??= new WeakMap());

		return $reader;
	}

	/** @return PathReadResult<mixed> */
	public function readPath(object $source, string $path): PathReadResult
	{
		$reader = isset($this->properties) ? $this : $this->forTraversal();
		$root = $reader->propertiesOf($source);
		if (array_key_exists($path, $root)) {
			return PathReadResult::present($root[$path]);
		}

		return self::traversePath($root, $path, $reader->propertiesOf(...));
	}

	/**
	 * Read paths against one representation, mapping each visited object once.
	 *
	 * @return Closure(string): PathReadResult<mixed>
	 */
	public function readPass(object $source): Closure
	{
		$reader = isset($this->properties) ? $this : $this->forTraversal();

		return static fn (string $path): PathReadResult => $reader->readPath($source, $path);
	}

	/** @return array<array-key, mixed> */
	private function propertiesOf(object $source): array
	{
		if (isset($this->properties[$source])) {
			return $this->properties[$source];
		}

		$result = map($source, gateway: $this->gateway)->shallow()->to([]);
		if (! is_array($result)) {
			throw new MappingException('Reading representation properties requires an array mapping result.');
		}

		return $this->properties[$source] = $result;
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
