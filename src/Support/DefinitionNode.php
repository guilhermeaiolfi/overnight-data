<?php

declare(strict_types=1);

namespace ON\Data\Support;

use LogicException;
use ON\Data\Definition\Registry;

/**
 * Minimal array-backed node bound directly to a registry-owned array slot.
 *
 * @phpstan-consistent-constructor
 */
abstract class DefinitionNode extends Dot
{
	private readonly Registry|self $owner;

	private readonly string $name;

	final protected function __construct(Registry|self $owner, string $name, array &$items)
	{
		$this->owner = $owner;
		$this->name = $name;

		parent::__construct([]);
		$this->setReference($items);
		$this->initializeRuntimeState();
	}

	/**
	 * Create a complete standalone definition array using this class's defaults.
	 *
	 * @internal
	 *
	 * @param array<array-key, mixed> $values
	 * @return array<array-key, mixed>
	 */
	final public static function createDefinition(array $values = []): array
	{
		/**
		 * @var array<array-key, mixed> $merged
		 */
		$merged = self::mergeDefinitionArrays(static::definitionDefaults(), $values);
		$merged['class'] = static::class;

		return $merged;
	}

	/**
	 * @internal
	 *
	 * @param array<array-key, mixed> $items
	 * @return static
	 */
	final public static function fromDefinition(Registry|self $owner, string $name, array &$items): static
	{
		return new static($owner, $name, $items);
	}

	final public function getName(): string
	{
		return $this->name;
	}

	final public function getRegistry(): Registry
	{
		return $this->owner instanceof Registry
			? $this->owner
			: $this->owner->getRegistry();
	}

	final public function __clone()
	{
		throw new LogicException('Definition nodes cannot be cloned.');
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected static function definitionDefaults(): array
	{
		return [];
	}

	protected function initializeRuntimeState(): void
	{
	}

	protected function owner(): Registry|self
	{
		return $this->owner;
	}

	/**
	 * @param array<array-key, mixed> $left
	 * @param array<array-key, mixed> $right
	 * @return array<array-key, mixed>
	 */
	private static function mergeDefinitionArrays(array $left, array $right): array
	{
		foreach ($right as $key => $value) {
			if (
				array_key_exists($key, $left)
				&& is_array($left[$key])
				&& is_array($value)
				&& ! array_is_list($left[$key])
				&& ! array_is_list($value)
			) {
				$left[$key] = self::mergeDefinitionArrays($left[$key], $value);

				continue;
			}

			$left[$key] = $value;
		}

		return $left;
	}
}
