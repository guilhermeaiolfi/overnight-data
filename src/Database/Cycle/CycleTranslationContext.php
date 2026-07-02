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
	 * @var list<SelectQuery>
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
			if ($source instanceof SelectQuery && $source->hasAlias() && ! $this->isCurrent($source)) {
				return $source->getAlias();
			}

			return $this->aliases[$id];
		}

		if ($source instanceof SelectQuery) {
			if ($source->hasAlias() && ! $this->isCurrent($source)) {
				return $this->aliases[$id] = $source->getAlias();
			}

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
		$this->stack[] = $query;
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
		if (! in_array($field->getQuery(), $this->stack, true)) {
			throw UnsupportedQueryException::forQuery(
				$this->root,
				sprintf(
					"Field '%s' is referenced outside the active query scope.",
					$field->getName(),
				)
			);
		}
	}

	public function assertSourceAccessible(QuerySourceInterface $source): void
	{
		foreach ($this->stack as $query) {
			if ($query === $source || $query->getFrom() === $source) {
				return;
			}
		}

		throw UnsupportedQueryException::forQuery(
			$this->root,
			"Source field is referenced outside the active query scope.",
		);
	}

	public function contains(SelectQuery $query): bool
	{
		return in_array($query, $this->stack, true);
	}

	public function isAncestor(SelectQuery $query): bool
	{
		return $this->stack !== [] && in_array($query, array_slice($this->stack, 0, -1), true);
	}

	public function isCurrent(SelectQuery $query): bool
	{
		return $this->stack !== [] && $query === $this->stack[array_key_last($this->stack)];
	}
}
