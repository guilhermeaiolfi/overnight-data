<?php

declare(strict_types=1);

namespace ON\Data\Definition\Plugin;

use Exception;
use LogicException;
use ON\Data\Support\DefinitionNode;
use ON\Data\Support\Dot;

/**
 * Array-backed definition plugin. {@see DefinitionNode::extend()} creates the
 * plugin in place on the host, the same way display/interface widgets are created.
 *
 * One-value plugins can store that payload with {@see value()} / {@see getValue()},
 * including {@see DefinitionNode::extend()} with a second argument.
 */
abstract class DefinitionPlugin extends DefinitionNode
{
	protected static function definitionDefaults(): array
	{
		return [
			'class' => static::class,
			'value' => null,
		];
	}

	public function value(mixed $value): static
	{
		$this->set('value', $value);

		return $this;
	}

	public function getValue(): mixed
	{
		return $this->get('value');
	}

	/**
	 * Plugins are not unbound drafts. Use {@see DefinitionNode::extend()} with this class.
	 *
	 * Signature matches {@see Dot::create()} so this override is reachable.
	 */
	public static function create(array|object|null $items = [], bool $parse = false, string $delimiter = '.'): static
	{
		unset($items, $parse, $delimiter);

		throw new LogicException(sprintf(
			'%s is created in place with extend(%s::class); do not call create().',
			static::class,
			static::class,
		));
	}

	public function end(): DefinitionNode
	{
		$owner = $this->owner();
		if (! $owner instanceof DefinitionNode) {
			throw new Exception('Plugin owner is invalid.');
		}

		return $owner;
	}
}
