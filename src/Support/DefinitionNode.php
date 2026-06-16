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
		$merged = self::mergeArrays(static::definitionDefaults(), $this->getArrayItems($items));

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
		$items = self::mergeArrays(static::definitionDefaults(), $items);
		$this->setReference($items);
	}

	/**
	 * @param array<array-key, mixed> $left
	 * @param array<array-key, mixed> $right
	 * @return array<array-key, mixed>
	 */
	private static function mergeArrays(array $left, array $right): array
	{
		foreach ($right as $key => $value) {
			if (is_int($key)) {
				$left[] = $value;

				continue;
			}

			if (isset($left[$key]) && is_array($left[$key]) && is_array($value)) {
				$left[$key] = self::mergeArrays($left[$key], $value);

				continue;
			}

			$left[$key] = $value;
		}

		return $left;
	}
}
