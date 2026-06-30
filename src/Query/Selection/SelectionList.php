<?php

declare(strict_types=1);

namespace ON\Data\Query\Selection;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use Traversable;

/**
 * @implements IteratorAggregate<int, SelectionItem>
 */
final class SelectionList implements IteratorAggregate, Countable
{
	/**
	 * @var list<SelectionItem>
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
			if (! $expression instanceof AliasedExpression) {
				continue;
			}

			$alias = $expression->getAlias();
			$matchingEntry = $this->findMatchingEntry($expression);

			if (
				(isset($this->namedExpressions[$alias]) && $matchingEntry === null)
				|| isset($incomingAliases[$alias])
			) {
				throw new InvalidArgumentException(sprintf("Query expression alias '%s' is already selected.", $alias));
			}

			$incomingAliases[$alias] = true;
			$incomingExpressions[$alias] = $expression->getExpression();
		}

		foreach ($expressions as $expression) {
			$promoted = false;

			foreach ($pendingEntries as $index => $entry) {
				if ($entry->isExplicit()) {
					continue;
				}

				if (! $this->expressionsMatch($entry->getExpression(), $expression)) {
					continue;
				}

				$pendingEntries[$index] = $entry->withExplicit();
				$promoted = true;

				break;
			}

			if (! $promoted) {
				$pendingEntries[] = new SelectionItem($expression, true);
			}
		}

		$this->entries = $pendingEntries;

		foreach ($incomingExpressions as $alias => $expression) {
			$this->namedExpressions[$alias] = $expression;
		}
	}

	/**
	 * @param string|list<string> $reasons
	 */
	public function add(
		ValueExpressionInterface|AliasedExpression $expression,
		string|array $reasons = [],
		bool $explicit = false,
	): SelectionItem {
		$normalizedReasons = $this->normalizeReasons($reasons);

		foreach ($this->entries as $index => $entry) {
			if (! $this->expressionsMatch($entry->getExpression(), $expression)) {
				continue;
			}

			$updated = $entry->withReasons($normalizedReasons);

			if ($explicit) {
				$updated = $updated->withExplicit();
			}

			$this->entries[$index] = $updated;

			return $updated;
		}

		if ($expression instanceof AliasedExpression && isset($this->namedExpressions[$expression->getAlias()])) {
			throw new InvalidArgumentException(sprintf("Query expression alias '%s' is already selected.", $expression->getAlias()));
		}

		$item = new SelectionItem($expression, $explicit, $normalizedReasons);
		$this->appendItem($item);

		return $item;
	}

	public function require(ValueExpressionInterface|AliasedExpression $expression, string $reason): void
	{
		$this->add($expression, $reason);
	}

	private function expressionsMatch(
		ValueExpressionInterface|AliasedExpression $left,
		ValueExpressionInterface|AliasedExpression $right,
	): bool {
		if ($left instanceof AliasedExpression || $right instanceof AliasedExpression) {
			return $left instanceof AliasedExpression
				&& $right instanceof AliasedExpression
				&& $left->getAlias() === $right->getAlias()
				&& $left->getExpression() === $right->getExpression();
		}

		return $left === $right;
	}

	/**
	 * @return list<SelectionItem>
	 */
	public function getAll(): array
	{
		return $this->entries;
	}

	/**
	 * @return list<SelectionItem>
	 */
	public function getExplicit(): array
	{
		return $this->filter(static fn (SelectionItem $selection): bool => $selection->isExplicit())->getAll();
	}

	/**
	 * @return list<SelectionItem>
	 */
	public function getImplicit(): array
	{
		return $this->filter(static fn (SelectionItem $selection): bool => $selection->isImplicit())->getAll();
	}

	/**
	 * @return list<SelectionItem>
	 */
	public function getByReason(string $reason): array
	{
		$reason = trim($reason);

		if ($reason === '') {
			throw new InvalidArgumentException('Selection reason lookups require a non-empty string.');
		}

		return $this->filter(static fn (SelectionItem $selection): bool => $selection->hasReason($reason))->getAll();
	}

	/**
	 * @param callable(SelectionItem): bool $predicate
	 */
	public function filter(callable $predicate): self
	{
		$filtered = new self();

		foreach ($this->entries as $entry) {
			if (! $predicate($entry)) {
				continue;
			}

			$filtered->appendItem($entry);
		}

		return $filtered;
	}

	/**
	 * @return list<SelectionItem>
	 */
	public function getParserItems(): array
	{
		return $this->entries;
	}

	/**
	 * @return list<SelectionItem>
	 */
	public function getPublicItems(): array
	{
		return $this->filter(
			static fn (SelectionItem $selection): bool => $selection->hasReason(SelectionReason::PUBLIC),
		)->getAll();
	}

	/**
	 * @return list<SelectionItem>
	 */
	public function getIdentityItems(): array
	{
		return $this->filter(
			static fn (SelectionItem $selection): bool => $selection->hasReason(SelectionReason::IDENTITY),
		)->getAll();
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
	 * @return Traversable<int, SelectionItem>
	 */
	public function getIterator(): Traversable
	{
		return new ArrayIterator($this->entries);
	}

	private function appendItem(SelectionItem $item): void
	{
		$this->entries[] = $item;

		$expression = $item->getExpression();

		if ($expression instanceof AliasedExpression) {
			$this->namedExpressions[$expression->getAlias()] = $expression->getExpression();
		}
	}

	/**
	 * @param string|list<string> $reasons
	 * @return list<string>
	 */
	private function normalizeReasons(string|array $reasons): array
	{
		if (is_string($reasons)) {
			return $reasons === '' ? [] : [$reasons];
		}

		return array_values($reasons);
	}

	private function findMatchingEntry(ValueExpressionInterface|AliasedExpression $expression): ?SelectionItem
	{
		foreach ($this->entries as $entry) {
			if ($this->expressionsMatch($entry->getExpression(), $expression)) {
				return $entry;
			}
		}

		return null;
	}
}
