<?php

declare(strict_types=1);

namespace ON\Data\Query\Selection;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\ValueExpressionInterface;
use Traversable;

/**
 * @implements IteratorAggregate<int, Selection>
 */
final class SelectionList implements IteratorAggregate, Countable
{
	/**
	 * @var list<Selection>
	 */
	private array $entries = [];

	/**
	 * @var array<string, ValueExpressionInterface>
	 */
	private array $namedExpressions = [];

	/**
	 * @param list<ValueExpressionInterface|AliasedExpression> $expressions
	 */
	public function addExplicit(array $expressions): void
	{
		$incomingAliases = [];
		$incomingExpressions = [];
		$pendingEntries = $this->entries;

		foreach ($expressions as $expression) {
			if ($expression instanceof AliasedExpression) {
				$alias = $expression->getAlias();

				if (isset($this->namedExpressions[$alias]) || isset($incomingExpressions[$alias])) {
					throw new InvalidArgumentException(sprintf("Query expression alias '%s' is already selected.", $alias));
				}

				$incomingAliases[] = $alias;
				$incomingExpressions[$alias] = $expression->getExpression();
			}

			$promoted = false;

			foreach ($pendingEntries as $index => $entry) {
				if ($entry->isExplicit()) {
					continue;
				}

				if ($entry->getExpression() !== $expression) {
					continue;
				}

				$pendingEntries[$index] = $entry->withExplicit();
				$promoted = true;

				break;
			}

			if (! $promoted) {
				$pendingEntries[] = new Selection($expression, true);
			}
		}

		$this->entries = $pendingEntries;

		foreach ($incomingAliases as $alias) {
			$this->namedExpressions[$alias] = $incomingExpressions[$alias];
		}
	}

	public function require(FieldRef $field, string $reason): void
	{
		foreach ($this->entries as $index => $entry) {
			if ($entry->getExpression() !== $field) {
				continue;
			}

			$this->entries[$index] = $entry->withReason($reason);

			return;
		}

		$this->entries[] = (new Selection($field))->withReason($reason);
	}

	/**
	 * @return list<Selection>
	 */
	public function getAll(): array
	{
		return $this->entries;
	}

	/**
	 * @return list<Selection>
	 */
	public function getExplicit(): array
	{
		return $this->getFiltered(static fn (Selection $selection): bool => $selection->isExplicit());
	}

	/**
	 * @return list<Selection>
	 */
	public function getImplicit(): array
	{
		return $this->getFiltered(static fn (Selection $selection): bool => $selection->isImplicit());
	}

	/**
	 * @return list<Selection>
	 */
	public function getByReason(string $reason): array
	{
		$reason = trim($reason);

		if ($reason === '') {
			throw new InvalidArgumentException('Selection reason lookups require a non-empty string.');
		}

		return $this->getFiltered(static fn (Selection $selection): bool => $selection->hasReason($reason));
	}

	/**
	 * @param callable(Selection): bool $predicate
	 * @return list<Selection>
	 */
	public function getFiltered(callable $predicate): array
	{
		return array_values(array_filter($this->entries, $predicate));
	}

	public function getNamedExpression(string $name): ValueExpressionInterface
	{
		return $this->namedExpressions[$name];
	}

	public function hasNamedExpression(string $name): bool
	{
		return isset($this->namedExpressions[$name]);
	}

	public function count(): int
	{
		return count($this->entries);
	}

	/**
	 * @return Traversable<int, Selection>
	 */
	public function getIterator(): Traversable
	{
		return new ArrayIterator($this->entries);
	}
}
