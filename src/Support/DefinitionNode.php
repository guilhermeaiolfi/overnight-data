<?php

declare(strict_types=1);

namespace ON\Data\Support;

use LogicException;
use ON\Data\Definition\Exception\InvalidDefinitionClassException;
use ON\Data\Definition\Plugin\DefinitionPlugin;
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

	/**
	 * @var array<class-string<DefinitionPlugin>, DefinitionPlugin>
	 */
	private array $plugins = [];

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
	 * Attach a plugin payload. Last write for the same plugin class on this node wins.
	 */
	public function extend(DefinitionPlugin $plugin): static
	{
		$class = $plugin::class;
		if (! isset($this->items['extend']) || ! is_array($this->items['extend'])) {
			$this->items['extend'] = [];
		}

		$this->items['extend'][$class] = $plugin->all();
		unset($this->plugins[$class]);

		return $this;
	}

	public function hasPlugin(string $class): bool
	{
		return isset($this->items['extend'][$class]) && is_array($this->items['extend'][$class]);
	}

	/**
	 * @template T of DefinitionPlugin
	 * @param class-string<T> $class
	 * @return T|null
	 */
	public function getPlugin(string $class): ?DefinitionPlugin
	{
		if (! is_a($class, DefinitionPlugin::class, true)) {
			throw new InvalidDefinitionClassException(sprintf('Invalid plugin class "%s".', $class));
		}

		if (isset($this->plugins[$class])) {
			/** @var T $cached */
			$cached = $this->plugins[$class];

			return $cached;
		}

		if (! $this->hasPlugin($class)) {
			return null;
		}

		$slot = &$this->items['extend'][$class];
		$storedClass = $slot['class'] ?? $class;
		if (! is_string($storedClass) || $storedClass !== $class) {
			throw new InvalidDefinitionClassException(sprintf(
				'Plugin slot "%s" has mismatched class discriminator "%s".',
				$class,
				is_string($storedClass) ? $storedClass : gettype($storedClass),
			));
		}

		$slot['class'] = $class;

		/** @var T $plugin */
		$plugin = $class::fromDefinition($this, $class, $slot);
		$this->plugins[$class] = $plugin;

		return $plugin;
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
		$this->plugins = [];
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
