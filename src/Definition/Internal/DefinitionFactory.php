<?php

declare(strict_types=1);

namespace ON\Data\Definition\Internal;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Display\DisplayInterface;
use ON\Data\Definition\Exception\InvalidDefinitionClassException;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Interface\InterfaceInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Definition\View\ViewDefinitionInterface;
use ON\Data\Support\DefinitionNode;

/**
 * @internal Creates and restores canonical array-backed definition wrappers.
 */
final class DefinitionFactory
{
	/**
	 * @param class-string<CollectionInterface> $class
	 * @param array<string, mixed> $slot
	 * @param array<string, mixed> $values
	 */
	public static function createCollection(Registry $owner, string $name, array &$slot, string $class, array $values = []): CollectionInterface
	{
		/** @var CollectionInterface $collection */
		$collection = self::create($owner, $name, $slot, $class, CollectionInterface::class, 'collection', $values);

		return $collection;
	}

	/**
	 * @param class-string<ViewDefinitionInterface> $class
	 * @param array<string, mixed> $slot
	 * @param array<string, mixed> $values
	 */
	public static function createView(Registry $owner, string $name, array &$slot, string $class, array $values = []): ViewDefinitionInterface
	{
		/** @var ViewDefinitionInterface $view */
		$view = self::create($owner, $name, $slot, $class, ViewDefinitionInterface::class, 'view', $values);

		return $view;
	}

	/**
	 * @param array<string, mixed> $slot
	 */
	public static function restoreCollection(Registry $owner, string $name, array &$slot): CollectionInterface
	{
		/** @var CollectionInterface $collection */
		$collection = self::restore($owner, $name, $slot, CollectionInterface::class, 'collection');

		return $collection;
	}

	/**
	 * @param array<string, mixed> $slot
	 */
	public static function restoreView(Registry $owner, string $name, array &$slot): ViewDefinitionInterface
	{
		/** @var ViewDefinitionInterface $view */
		$view = self::restore($owner, $name, $slot, ViewDefinitionInterface::class, 'view');

		return $view;
	}

	/**
	 * @param class-string<FieldInterface> $class
	 * @param array<string, mixed> $slot
	 * @param array<string, mixed> $values
	 */
	public static function createField(DefinitionInterface $owner, string $name, array &$slot, string $class, array $values = []): FieldInterface
	{
		/** @var FieldInterface $field */
		$field = self::create($owner, $name, $slot, $class, FieldInterface::class, 'field', $values);

		return $field;
	}

	/**
	 * @param array<string, mixed> $slot
	 */
	public static function restoreField(DefinitionInterface $owner, string $name, array &$slot): FieldInterface
	{
		/** @var FieldInterface $field */
		$field = self::restore($owner, $name, $slot, FieldInterface::class, 'field');

		return $field;
	}

	/**
	 * @param class-string<RelationInterface> $class
	 * @param array<string, mixed> $slot
	 * @param array<string, mixed> $values
	 */
	public static function createRelation(DefinitionInterface $owner, string $name, array &$slot, string $class, array $values = []): RelationInterface
	{
		/** @var RelationInterface $relation */
		$relation = self::create($owner, $name, $slot, $class, RelationInterface::class, 'relation', $values);

		return $relation;
	}

	/**
	 * @param array<string, mixed> $slot
	 */
	public static function restoreRelation(DefinitionInterface $owner, string $name, array &$slot): RelationInterface
	{
		/** @var RelationInterface $relation */
		$relation = self::restore($owner, $name, $slot, RelationInterface::class, 'relation');

		return $relation;
	}

	/**
	 * @param class-string<DisplayInterface> $class
	 * @param array<string, mixed> $slot
	 * @param array<string, mixed> $values
	 */
	public static function createDisplay(DefinitionNode $owner, string $name, array &$slot, string $class, array $values = []): DisplayInterface
	{
		/** @var DisplayInterface $display */
		$display = self::create($owner, $name, $slot, $class, DisplayInterface::class, 'display', $values);

		return $display;
	}

	/**
	 * @param array<string, mixed> $slot
	 */
	public static function restoreDisplay(DefinitionNode $owner, string $name, array &$slot): DisplayInterface
	{
		/** @var DisplayInterface $display */
		$display = self::restore($owner, $name, $slot, DisplayInterface::class, 'display');

		return $display;
	}

	/**
	 * @param class-string<InterfaceInterface> $class
	 * @param array<string, mixed> $slot
	 * @param array<string, mixed> $values
	 */
	public static function createInterface(DefinitionNode $owner, string $name, array &$slot, string $class, array $values = []): InterfaceInterface
	{
		/** @var InterfaceInterface $interface */
		$interface = self::create($owner, $name, $slot, $class, InterfaceInterface::class, 'interface', $values);

		return $interface;
	}

	/**
	 * @param array<string, mixed> $slot
	 */
	public static function restoreInterface(DefinitionNode $owner, string $name, array &$slot): InterfaceInterface
	{
		/** @var InterfaceInterface $interface */
		$interface = self::restore($owner, $name, $slot, InterfaceInterface::class, 'interface');

		return $interface;
	}

	/**
	 * @template T of object
	 *
	 * @param class-string<T> $class
	 * @param class-string<T> $expectedType
	 * @param array<string, mixed> $slot
	 * @param array<string, mixed> $values
	 * @return T
	 */
	public static function create(
		Registry|DefinitionNode $owner,
		string $name,
		array &$slot,
		string $class,
		string $expectedType,
		string $context,
		array $values = [],
	): object {
		self::assertClass($class, $expectedType, $context);
		$slot = $class::createDefinition($values);

		/** @var T&DefinitionNode $node */
		$node = $class::fromDefinition($owner, $name, $slot);

		return $node;
	}

	/**
	 * @template T of object
	 *
	 * @param class-string<T> $expectedType
	 * @param array<string, mixed> $slot
	 * @return T
	 */
	public static function restore(
		Registry|DefinitionNode $owner,
		string $name,
		array &$slot,
		string $expectedType,
		string $context,
	): object {
		$class = self::requireStoredClass($slot, $expectedType, $context);

		/** @var T&DefinitionNode $node */
		$node = $class::fromDefinition($owner, $name, $slot);

		return $node;
	}

	/**
	 * @param array<string, mixed> $items
	 * @param class-string $expectedType
	 * @return class-string
	 */
	public static function requireStoredClass(array $items, string $expectedType, string $context): string
	{
		$class = $items['class'] ?? null;
		if (! is_string($class) || $class === '') {
			throw new InvalidDefinitionClassException(sprintf('%s definition is missing required class discriminator.', ucfirst($context)));
		}

		self::assertClass($class, $expectedType, $context);

		return $class;
	}

	/**
	 * @param class-string $expectedType
	 */
	private static function assertClass(string $class, string $expectedType, string $context): void
	{
		if (! class_exists($class)) {
			throw new InvalidDefinitionClassException(sprintf('Unknown %s class "%s".', $context, $class));
		}

		if (! is_a($class, $expectedType, true)) {
			throw new InvalidDefinitionClassException(sprintf('Invalid %s class "%s".', $context, $class));
		}

		if (! is_a($class, DefinitionNode::class, true)) {
			throw new InvalidDefinitionClassException(
				sprintf('Stored %s class "%s" must extend %s.', $context, $class, DefinitionNode::class)
			);
		}
	}
}
