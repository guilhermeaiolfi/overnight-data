<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Mapper;

use ON\Data\Mapper\Exception\MappingException;

/**
 * Mapping argument controlling how concrete objects nest under stdClass targets.
 *
 * Pass via {@see \ON\Data\Mapper\MapBuilder::args()} the same way as
 * {@see ArrayMapperOptions}:
 *
 * ```php
 * map($row)->args(new ObjectMapperOptions(convertNestedObjects: true))->to(stdClass::class);
 * ```
 *
 * Default: nested arrays and stdClass still recurse; other objects pass through as leaves.
 */
final readonly class ObjectMapperOptions
{
	public function __construct(
		private bool $convertNestedObjects = false,
	) {
	}

	public function convertsNestedObjects(): bool
	{
		return $this->convertNestedObjects;
	}

	/**
	 * @param list<mixed> $arguments
	 */
	public static function fromArguments(array $arguments): self
	{
		$options = [];

		foreach ($arguments as $argument) {
			if ($argument instanceof self) {
				$options[] = $argument;
			}
		}

		if ($options === []) {
			return new self();
		}

		if (count($options) > 1) {
			throw new MappingException(
				'ObjectMapperOptions is ambiguous: mapping arguments contain multiple direct options.',
			);
		}

		return $options[0];
	}
}
