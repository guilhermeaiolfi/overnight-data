<?php

declare(strict_types=1);

namespace ON\Data\Definition\Plugin;

use InvalidArgumentException;
use ON\Data\Definition\Registry;
use ON\Data\Support\DefinitionNode;
use ON\Data\Support\Dot;

/**
 * Array-backed definition plugin. {@see create()} returns an unbound draft;
 * {@see DefinitionNode::extend()} copies that payload onto the host node.
 */
abstract class DefinitionPlugin extends DefinitionNode
{
	/**
	 * Unbound plugin bound to a throwaway registry until {@see DefinitionNode::extend()}.
	 *
	 * Signature matches {@see Dot::create()}; do not pass Dot items.
	 */
	public static function create(array|object|null $items = [], bool $parse = false, string $delimiter = '.'): static
	{
		if ($items !== [] && $items !== null) {
			throw new InvalidArgumentException(
				sprintf('%s::create() builds an unbound plugin draft; do not pass Dot items.', static::class),
			);
		}

		unset($parse, $delimiter);
		$payload = static::createDefinition();

		return static::fromDefinition(self::draftOwner(), static::class, $payload);
	}

	public function end(): Registry|DefinitionNode
	{
		return $this->owner();
	}

	private static function draftOwner(): Registry
	{
		static $owner;

		return $owner ??= new Registry();
	}
}
