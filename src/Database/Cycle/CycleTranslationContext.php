<?php

declare(strict_types=1);

namespace ON\Data\Database\Cycle;

use Cycle\Database\Driver\CompilerInterface;
use ON\Data\Database\Exception\UnsupportedQueryException;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\SelectQuery;

/**
 * @internal
 */
final class CycleTranslationContext
{
	/**
	 * @var array<int, string>
	 */
	private array $aliases = [];

	/**
	 * @var list<int>
	 */
	private array $stack = [];

	private int $nextAlias = 0;

	public function __construct(
		private readonly SelectQuery $root,
		private readonly CompilerInterface $compiler,
	) {
	}

	public function root(): SelectQuery
	{
		return $this->root;
	}

	public function compiler(): CompilerInterface
	{
		return $this->compiler;
	}

	public function aliasFor(SelectQuery $query): string
	{
		$id = spl_object_id($query);

		return $this->aliases[$id] ??= 'q' . $this->nextAlias++;
	}

	/**
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	public function within(SelectQuery $query, callable $callback): mixed
	{
		if ($this->contains($query)) {
			throw UnsupportedQueryException::forQuery(
				$query,
				'Cyclic query references are not supported.',
			);
		}

		$this->stack[] = spl_object_id($query);

		try {
			return $callback();
		} finally {
			array_pop($this->stack);
		}
	}

	public function assertAccessible(FieldRef $field): void
	{
		$id = spl_object_id($field->getQuery());
		if (! in_array($id, $this->stack, true)) {
			throw UnsupportedQueryException::forQuery(
				$this->root,
				sprintf(
					"Field '%s' is referenced outside the active query scope.",
					$field->getName(),
				)
			);
		}
	}

	public function contains(SelectQuery $query): bool
	{
		return in_array(spl_object_id($query), $this->stack, true);
	}

	public function isAncestor(SelectQuery $query): bool
	{
		$id = spl_object_id($query);

		return $this->stack !== [] && in_array($id, array_slice($this->stack, 0, -1), true);
	}

	public function isCurrent(SelectQuery $query): bool
	{
		$id = spl_object_id($query);

		return $this->stack !== [] && $id === $this->stack[array_key_last($this->stack)];
	}
}
