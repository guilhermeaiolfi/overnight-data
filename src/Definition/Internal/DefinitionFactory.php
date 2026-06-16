<?php

declare(strict_types=1);

namespace ON\Data\Definition\Internal;

use Closure;
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
 * @internal Restores and rebinds canonical array-backed definition wrappers.
 */
final class DefinitionFactory
{
	/**
	 * @param array<string, mixed> $items
	 */
	public static function collection(Registry $registry, array &$items): CollectionInterface
	{
		/** @var CollectionInterface $collection */
		$collection = self::node($registry, $items, CollectionInterface::class, 'collection');

		return $collection;
	}

	/**
	 * @param array<string, mixed> $items
	 */
	public static function view(Registry $registry, array &$items): ViewDefinitionInterface
	{
		/** @var ViewDefinitionInterface $view */
		$view = self::node($registry, $items, ViewDefinitionInterface::class, 'view');

		return $view;
	}

	/**
	 * @param array<string, mixed> $items
	 */
	public static function field(DefinitionInterface $definition, array &$items): FieldInterface
	{
		/** @var FieldInterface $field */
		$field = self::node($definition, $items, FieldInterface::class, 'field');

		return $field;
	}

	/**
	 * @param array<string, mixed> $items
	 */
	public static function relation(DefinitionInterface $definition, array &$items): RelationInterface
	{
		/** @var RelationInterface $relation */
		$relation = self::node($definition, $items, RelationInterface::class, 'relation');

		return $relation;
	}

	/**
	 * @param array<string, mixed> $items
	 */
	public static function display(mixed $parent, array &$items): DisplayInterface
	{
		/** @var DisplayInterface $display */
		$display = self::node($parent, $items, DisplayInterface::class, 'display');

		return $display;
	}

	/**
	 * @param array<string, mixed> $items
	 */
	public static function interface(mixed $parent, array &$items): InterfaceInterface
	{
		/** @var InterfaceInterface $interface */
		$interface = self::node($parent, $items, InterfaceInterface::class, 'interface');

		return $interface;
	}

	/**
	 * @template T of object
	 *
	 * @param class-string<T> $expectedType
	 * @param array<string, mixed> $items
	 * @return T
	 *
	 * @internal
	 */
	public static function node(
		object $parent,
		array &$items,
		string $expectedType,
		string $context,
	): object {
		$class = self::requireStoredClass($items, $expectedType, $context);

		/** @var T&DefinitionNode $node */
		$node = new $class($parent);
		self::rebind($node, $items);

		return $node;
	}

	/**
	 * @param class-string $expectedType
	 * @return array<string, mixed>
	 *
	 * @internal
	 */
	public static function export(object $node, string $expectedType, string $context): array
	{
		self::assertDefinitionNodeInstance($node, $expectedType, $context);

		/** @var DefinitionNode $node */
		return $node->all();
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
	 * @param class-string $expectedType
	 * @return class-string
	 */
	public static function requireStoredClass(array $items, string $expectedType, string $context): string
	{
		$class = $items['class'] ?? null;
		if (! is_string($class) || $class === '') {
			throw new InvalidDefinitionClassException(sprintf('%s definition is missing required class discriminator.', ucfirst($context)));
		}

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

		return $class;
	}

	/**
	 * @param class-string $expectedType
	 */
	private static function assertDefinitionNodeInstance(object $node, string $expectedType, string $context): void
	{
		if (! $node instanceof $expectedType) {
			throw new InvalidDefinitionClassException(
				sprintf('Invalid %s instance "%s".', $context, $node::class)
			);
		}

		if (! $node instanceof DefinitionNode) {
			throw new InvalidDefinitionClassException(
				sprintf('Stored %s instance "%s" must extend %s.', $context, $node::class, DefinitionNode::class)
			);
		}
	}
}
