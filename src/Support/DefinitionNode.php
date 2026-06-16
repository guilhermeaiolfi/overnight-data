<?php

declare(strict_types=1);

namespace ON\Data\Support;

/**
 * Minimal array-backed node for future definition wrappers.
 *
 * @phpstan-consistent-constructor
 */
class DefinitionNode extends Dot
{
	/**
	 * Create a complete standalone definition array using this class's defaults.
	 *
	 * @internal
	 *
	 * @param array<array-key, mixed> $values
	 * @return array<array-key, mixed>
	 */
	public static function createDefinition(array $values = []): array
	{
		/**
		 * @var array<array-key, mixed> $merged
		 */
		$merged = self::mergeDefinitionArrays(static::definitionDefaults(), $values);

		return $merged;
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected static function definitionDefaults(): array
	{
		$defaults = get_class_vars(static::class);
		unset($defaults['items'], $defaults['delimiter']);

		return $defaults;
	}

	/**
	 * @param array<array-key, mixed>|object|null $items
	 * @param string $delimiter
	 */
	public function __construct(array|object|null $items = [], bool $parse = false, string $delimiter = '.')
	{
		/**
		 * @var array<array-key, mixed> $merged
		 */
		$merged = static::createDefinition($this->getArrayItems($items));

		parent::__construct($merged, $parse, $delimiter);
	}

	/**
	 * @param array<array-key, mixed> $items
	 * @param string $delimiter
	 */
	public static function fromReference(array &$items, string $delimiter = '.'): static
	{
		$instance = new static([], false, $delimiter);
		$instance->bind($items);

		return $instance;
	}

	/**
	 * @param array<array-key, mixed> $items
	 */
	protected function bind(array &$items): void
	{
		$this->setReference($items);
	}

	/**
	 * @internal Rebinds the wrapper to a registry-owned nested definition array.
	 *
	 * @param array<array-key, mixed> $items
	 */
	protected function rebindDefinitionArray(array &$items): void
	{
		$this->bind($items);
		$this->afterBindDefinitionArray();
	}

	/**
	 * @internal Allows subclasses to rebuild nested wrapper caches after rebinding.
	 */
	protected function afterBindDefinitionArray(): void
	{
	}

	/**
	 * @param array<array-key, mixed> $items
	 * @return array<array-key, mixed>
	 */
	protected static function detachArray(array $items): array
	{
		$detached = unserialize(serialize($items), ['allowed_classes' => false]);

		return is_array($detached) ? $detached : [];
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
