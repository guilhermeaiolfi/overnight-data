<?php

declare(strict_types=1);

namespace ON\Data\Query\Selection;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\SourceFieldExpression;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\QuerySourceInterface;
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
	 * @var array<string, list<int>>
	 */
	private array $reasonEntryIndexes = [];

	/**
	 * @param list<ValueExpressionInterface|AliasedExpression|StarExpression> $expressions
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
			$explicitReasons = $this->inferReasons($expression, [], true);

			foreach ($pendingEntries as $index => $entry) {
				if ($entry->isExplicit()) {
					continue;
				}

				if (! $this->expressionsMatch($entry->getExpression(), $expression)) {
					continue;
				}

				$pendingEntries[$index] = $entry
					->withReasons($explicitReasons)
					->withExplicit();
				$promoted = true;

				break;
			}

			if (! $promoted) {
				$pendingEntries[] = new SelectionItem($expression, true, $explicitReasons);
			}
		}

		$this->entries = $pendingEntries;
		$this->rebuildReasonIndexes();

		foreach ($incomingExpressions as $alias => $expression) {
			$this->namedExpressions[$alias] = $expression;
		}
	}

	/**
	 * @param string|list<string> $reasons
	 */
	public function add(
		ValueExpressionInterface|AliasedExpression|StarExpression $expression,
		string|array $reasons = [],
		bool $explicit = false,
	): SelectionItem {
		$normalizedReasons = $this->inferReasons($expression, $this->normalizeReasons($reasons), $explicit);

		foreach ($this->entries as $index => $entry) {
			if (! $this->expressionsMatch($entry->getExpression(), $expression)) {
				continue;
			}

			$newReasons = array_values(array_filter(
				$normalizedReasons,
				static fn (string $reason): bool => ! $entry->hasReason($reason),
			));
			$updated = $entry->withReasons($normalizedReasons);

			if ($explicit) {
				$updated = $updated->withExplicit();
			}

			$this->entries[$index] = $updated;
			$this->registerReasonIndexes($index, $newReasons);

			return $updated;
		}

		if ($expression instanceof AliasedExpression && isset($this->namedExpressions[$expression->getAlias()])) {
			throw new InvalidArgumentException(sprintf("Query expression alias '%s' is already selected.", $expression->getAlias()));
		}

		$item = new SelectionItem($expression, $explicit, $normalizedReasons);
		$this->appendItem($item, $normalizedReasons);

		return $item;
	}

	/**
	 * @param string|list<string> $reasons
	 */
	public function tag(
		ValueExpressionInterface|AliasedExpression|StarExpression|string $selection,
		string|array $reasons,
	): SelectionItem {
		$normalizedReasons = $this->normalizeReasons($reasons);

		if ($normalizedReasons === []) {
			throw new InvalidArgumentException('Selection tagging requires at least one non-empty reason.');
		}

		foreach ($this->entries as $index => $entry) {
			if (! $this->selectionMatches($entry, $selection)) {
				continue;
			}

			$newReasons = array_values(array_filter(
				$normalizedReasons,
				static fn (string $reason): bool => ! $entry->hasReason($reason),
			));
			$updated = $entry->withReasons($normalizedReasons);
			$this->entries[$index] = $updated;
			$this->registerReasonIndexes($index, $newReasons);

			return $updated;
		}

		throw new InvalidArgumentException('Cannot tag a selection that is not present in the list.');
	}

	public function require(ValueExpressionInterface|AliasedExpression|StarExpression $expression, string $reason): void
	{
		$this->add($expression, $reason);
	}

	public function merge(self $other, ?bool $explicit = null): void
	{
		foreach ($other->getAll() as $selection) {
			$this->add(
				$selection->getExpression(),
				$selection->getReasons(),
				$explicit ?? $selection->isExplicit(),
			);
		}
	}

	public function projectTo(QuerySourceInterface $from, QuerySourceInterface $to): self
	{
		$projected = new self();

		foreach ($this->entries as $entry) {
			$projected->add(
				$entry->getProjectedExpression($from, $to),
				$entry->getReasons(),
				$entry->isExplicit(),
			);
		}

		return $projected;
	}

	public function ensureField(FieldRef|SourceFieldExpression $field, string $reason): SelectionItem
	{
		return $this->add($field, $reason);
	}

	public function ensureInternalField(FieldRef|SourceFieldExpression $field): SelectionItem
	{
		foreach ($this->entries as $entry) {
			if ($entry->getSelectionKey() !== $field->getSelectionKey()) {
				continue;
			}

			if ($entry->isExplicit() || $entry->getExpression() instanceof AliasedExpression) {
				return $this->add($entry->getExpression(), SelectionReason::INTERNAL, $entry->isExplicit());
			}

			break;
		}

		return $this->add($field->as($field->getSelectionKey()), SelectionReason::INTERNAL);
	}

	public function ensureInternalExpression(ValueExpressionInterface $expression, string $alias): SelectionItem
	{
		return $this->add($expression->as($alias), [SelectionReason::INTERNAL, SelectionReason::SQL_ONLY]);
	}

	private function expressionsMatch(
		ValueExpressionInterface|AliasedExpression|StarExpression $left,
		ValueExpressionInterface|AliasedExpression|StarExpression $right,
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

	public function filterByReason(string $reason): self
	{
		return $this->filter(static fn (SelectionItem $selection): bool => $selection->hasReason($reason));
	}

	/**
	 * @param callable(SelectionItem): bool $predicate
	 */
	public function filter(callable $predicate): self
	{
		$filtered = new self();
		$indexMap = [];

		foreach ($this->entries as $index => $entry) {
			if (! $predicate($entry)) {
				continue;
			}

			$indexMap[$index] = count($filtered->entries);
			$filtered->appendItem($entry);
		}

		foreach ($this->reasonEntryIndexes as $reason => $indexes) {
			foreach ($indexes as $index) {
				if (! isset($indexMap[$index])) {
					continue;
				}

				$filtered->registerReasonIndex($reason, $indexMap[$index]);
			}
		}

		return $filtered;
	}

	/**
	 * @return list<SelectionItem>
	 */
	public function getParserItems(): array
	{
		return $this->getByReason(SelectionReason::COLUMN);
	}

	/**
	 * @return list<SelectionItem>
	 */
	public function getPublicItems(): array
	{
		return $this->getItemsInReasonOrder(SelectionReason::PUBLIC);
	}

	/**
	 * @return list<SelectionItem>
	 */
	public function getIdentityItems(): array
	{
		return $this->getItemsInReasonOrder(SelectionReason::IDENTITY);
	}

	public function getNamedExpression(string $name): ValueExpressionInterface
	{
		return $this->namedExpressions[$name];
	}

	public function hasNamedExpression(string $name): bool
	{
		return isset($this->namedExpressions[$name]);
	}

	public function hasSelectionKey(string $name): bool
	{
		foreach ($this->entries as $entry) {
			if ($entry->getSelectionKey() === $name) {
				return true;
			}
		}

		return false;
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

	/**
	 * @param list<string> $reasons
	 */
	private function appendItem(SelectionItem $item, array $reasons = []): void
	{
		$this->entries[] = $item;
		$index = array_key_last($this->entries);

		$expression = $item->getExpression();

		if ($expression instanceof AliasedExpression) {
			$this->namedExpressions[$expression->getAlias()] = $expression->getExpression();
		}

		if (is_int($index)) {
			$this->registerReasonIndexes($index, $reasons);
		}
	}

	/**
	 * @param string|list<string> $reasons
	 * @return list<string>
	 */
	private function normalizeReasons(string|array $reasons): array
	{
		if (is_string($reasons)) {
			$reasons = trim($reasons);

			return $reasons === '' ? [] : [$reasons];
		}

		return array_values(array_filter(array_map(
			static fn (string $reason): string => trim($reason),
			$reasons,
		), static fn (string $reason): bool => $reason !== ''));
	}

	private function findMatchingEntry(ValueExpressionInterface|AliasedExpression|StarExpression $expression): ?SelectionItem
	{
		foreach ($this->entries as $entry) {
			if ($this->expressionsMatch($entry->getExpression(), $expression)) {
				return $entry;
			}
		}

		return null;
	}

	private function selectionMatches(
		SelectionItem $entry,
		ValueExpressionInterface|AliasedExpression|StarExpression|string $selection,
	): bool {
		if (is_string($selection)) {
			return $entry->getSelectionKey() === trim($selection);
		}

		return $this->expressionsMatch($entry->getExpression(), $selection);
	}

	/**
	 * @param list<string> $reasons
	 */
	private function registerReasonIndexes(int $index, array $reasons): void
	{
		foreach ($reasons as $reason) {
			$this->registerReasonIndex($reason, $index);
		}
	}

	private function registerReasonIndex(string $reason, int $index): void
	{
		$this->reasonEntryIndexes[$reason] ??= [];

		if (in_array($index, $this->reasonEntryIndexes[$reason], true)) {
			return;
		}

		$this->reasonEntryIndexes[$reason][] = $index;
	}

	private function rebuildReasonIndexes(): void
	{
		$this->reasonEntryIndexes = [];

		foreach ($this->entries as $index => $entry) {
			$this->registerReasonIndexes($index, $entry->getReasons());
		}
	}

	/**
	 * @param list<string> $callerReasons
	 * @return list<string>
	 */
	private function inferReasons(
		ValueExpressionInterface|AliasedExpression|StarExpression $expression,
		array $callerReasons,
		bool $explicit,
	): array {
		$inferred = $callerReasons;

		if ($explicit) {
			$inferred[] = SelectionReason::COLUMN;
			$inferred[] = SelectionReason::PUBLIC;
		}

		if ($this->isFieldLike($expression)) {
			$inferred[] = SelectionReason::COLUMN;
		}

		return array_values(array_unique(array_map('trim', $inferred)));
	}

	private function isFieldLike(ValueExpressionInterface|AliasedExpression|StarExpression $expression): bool
	{
		if ($expression instanceof AliasedExpression) {
			$expression = $expression->getExpression();
		}

		return $expression instanceof FieldRef || $expression instanceof SourceFieldExpression;
	}

	/**
	 * @return list<SelectionItem>
	 */
	private function getItemsInReasonOrder(string $reason): array
	{
		$items = [];

		foreach ($this->reasonEntryIndexes[$reason] ?? [] as $index) {
			$item = $this->entries[$index] ?? null;

			if (! $item instanceof SelectionItem) {
				continue;
			}

			$items[] = $item;
		}

		return $items;
	}
}
