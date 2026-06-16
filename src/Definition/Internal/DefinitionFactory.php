<?php

declare(strict_types=1);

namespace ON\Data\Definition\Internal;

use Closure;
use ON\Data\Definition\Collection\Collection;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Display\DisplayInterface;
use ON\Data\Definition\Display\RawDisplay;
use ON\Data\Definition\Exception\InvalidDefinitionClassException;
use ON\Data\Definition\Field\Field;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Interface\InterfaceInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\Definition\Relation\M2MThrough;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Definition\View\ViewDefinition;
use ON\Data\Definition\View\ViewDefinitionInterface;
use ON\Data\Support\DefinitionNode;

final class DefinitionFactory
{
	/**
	 * @param array<string, mixed> $items
	 */
	public static function collection(Registry $registry, array &$items): CollectionInterface
	{
		$class = self::normalizeClass($items, 'class', Collection::class, CollectionInterface::class, 'collection');

		/** @var CollectionInterface&DefinitionNode $collection */
		$collection = new $class($registry);
		self::rebind($collection, $items);

		return $collection;
	}

	/**
	 * @param array<string, mixed> $items
	 */
	public static function view(Registry $registry, array &$items): ViewDefinitionInterface
	{
		$class = self::normalizeClass($items, 'class', ViewDefinition::class, ViewDefinitionInterface::class, 'view');

		/** @var ViewDefinitionInterface&DefinitionNode $view */
		$view = new $class($registry);
		self::rebind($view, $items);

		return $view;
	}

	/**
	 * @param array<string, mixed> $items
	 */
	public static function field(DefinitionInterface $definition, array &$items): FieldInterface
	{
		$class = self::normalizeClass($items, 'class', Field::class, FieldInterface::class, 'field');

		/** @var FieldInterface&DefinitionNode $field */
		$field = new $class($definition);
		self::rebind($field, $items);

		return $field;
	}

	/**
	 * @param array<string, mixed> $items
	 */
	public static function relation(DefinitionInterface $definition, array &$items): RelationInterface
	{
		if (! array_key_exists('class', $items)) {
			throw new InvalidDefinitionClassException('Relation definition is missing required class discriminator.');
		}

		$class = self::normalizeClass($items, 'class', null, RelationInterface::class, 'relation');

		/** @var RelationInterface&DefinitionNode $relation */
		$relation = new $class($definition);
		self::rebind($relation, $items);

		return $relation;
	}

	/**
	 * @param array<string, mixed> $items
	 */
	public static function display(mixed $parent, array &$items): DisplayInterface
	{
		$class = self::normalizeClass($items, 'class', RawDisplay::class, DisplayInterface::class, 'display');

		/** @var DisplayInterface&DefinitionNode $display */
		$display = new $class($parent);
		self::rebind($display, $items);

		return $display;
	}

	/**
	 * @param array<string, mixed> $items
	 */
	public static function interface(mixed $parent, array &$items): InterfaceInterface
	{
		$class = self::normalizeClass($items, 'class', null, InterfaceInterface::class, 'interface');

		/** @var InterfaceInterface&DefinitionNode $interface */
		$interface = new $class($parent);
		self::rebind($interface, $items);

		return $interface;
	}

	/**
	 * @param array<string, mixed> $items
	 */
	public static function through(M2MRelation $relation, array &$items): M2MThrough
	{
		$through = new M2MThrough($relation);
		self::rebind($through, $items);

		return $through;
	}

	/**
	 * @param array<string, mixed> $items
	 */
	public static function rebind(DefinitionNode $node, array &$items): void
	{
		/** @var Closure(DefinitionNode, array<string, mixed>&): void $binder */
		$binder = Closure::bind(
			static function (DefinitionNode $node, array &$items): void {
				$node->rebindDefinitionArray($items);
			},
			null,
			DefinitionNode::class
		);

		$binder($node, $items);
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
			throw new InvalidDefinitionClassException(sprintf('Invalid %s class discriminator.', $context));
		}

		if (! class_exists($class)) {
			throw new InvalidDefinitionClassException(sprintf('Unknown %s class "%s".', $context, $class));
		}

		if (! is_a($class, $expectedType, true)) {
			throw new InvalidDefinitionClassException(sprintf('Invalid %s class "%s".', $context, $class));
		}

		$items[$key] = $class;

		return $class;
	}
}
