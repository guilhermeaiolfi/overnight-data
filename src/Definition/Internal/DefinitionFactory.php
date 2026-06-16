<?php

declare(strict_types=1);

namespace ON\Data\Definition\Internal;

use InvalidArgumentException;
use ON\Data\Definition\Collection\Collection;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Display\DisplayInterface;
use ON\Data\Definition\Display\RawDisplay;
use ON\Data\Definition\Field\Field;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Interface\InterfaceInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\RelationInterface;

final class DefinitionFactory
{
	/**
	 * @param array<string, mixed> $items
	 */
	public static function collection(Registry $registry, array &$items): CollectionInterface
	{
		$class = self::normalizeClass($items, 'class', Collection::class, CollectionInterface::class, 'collection');

		return new $class($registry, $items);
	}

	/**
	 * @param array<string, mixed> $items
	 */
	public static function field(CollectionInterface $collection, array &$items): FieldInterface
	{
		$class = self::normalizeClass($items, 'class', Field::class, FieldInterface::class, 'field');

		return new $class($collection, $items);
	}

	/**
	 * @param array<string, mixed> $items
	 */
	public static function relation(CollectionInterface $collection, array &$items): RelationInterface
	{
		if (! array_key_exists('class', $items)) {
			throw new InvalidArgumentException('Relation definition is missing required class discriminator.');
		}

		$class = self::normalizeClass($items, 'class', null, RelationInterface::class, 'relation');

		return new $class($collection, $items);
	}

	/**
	 * @param array<string, mixed> $items
	 */
	public static function display(mixed $parent, array &$items): DisplayInterface
	{
		$class = self::normalizeClass($items, 'class', RawDisplay::class, DisplayInterface::class, 'display');

		return new $class($parent, $items);
	}

	/**
	 * @param array<string, mixed> $items
	 */
	public static function interface(mixed $parent, array &$items): InterfaceInterface
	{
		$class = self::normalizeClass($items, 'class', null, InterfaceInterface::class, 'interface');

		return new $class($parent, $items);
	}

	/**
	 * @param array<string, mixed> $items
	 * @param class-string|null $defaultClass
	 * @return class-string
	 */
	private static function normalizeClass(
		array &$items,
		string $key,
		?string $defaultClass,
		string $expectedType,
		string $context,
	): string {
		$class = $items[$key] ?? $defaultClass;
		if (! is_string($class) || $class === '') {
			throw new InvalidArgumentException(sprintf('Invalid %s class discriminator.', $context));
		}

		if (! class_exists($class)) {
			throw new InvalidArgumentException(sprintf('Unknown %s class "%s".', $context, $class));
		}

		if (! is_a($class, $expectedType, true)) {
			throw new InvalidArgumentException(sprintf('Invalid %s class "%s".', $context, $class));
		}

		$items[$key] = $class;

		return $class;
	}
}
