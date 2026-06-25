<?php

declare(strict_types=1);

namespace ON\Data\Database\Cycle;

use Cycle\Database\Driver\CompilerInterface;
use ON\Data\Database\Exception\UnsupportedQueryException;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\QuerySourceInterface;
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
	 * @var array<int, int>
	 */
	private array $nextJoinAlias = [];

	private int $nextQueryAlias = 0;

	/**
	 * @var list<int>
	 */
	private array $stack = [];

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

	public function aliasFor(QuerySourceInterface $source): string
	{
		$id = spl_object_id($source);

		if (isset($this->aliases[$id])) {
			return $this->aliases[$id];
		}

		if ($source instanceof SelectQuery) {
			return $this->aliases[$id] = 'q' . $this->nextQueryAlias++;
		}

		$queryId = spl_object_id($source->getQuery());
		$next = $this->nextJoinAlias[$queryId] ?? 0;
		$this->nextJoinAlias[$queryId] = $next + 1;

		return $this->aliases[$id] = 'j' . $next;
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

		$id = spl_object_id($query);
		$this->stack[] = $id;
		$this->aliases[$id] ??= 'q' . $this->nextQueryAlias++;
		$this->nextJoinAlias[$id] ??= 0;

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
