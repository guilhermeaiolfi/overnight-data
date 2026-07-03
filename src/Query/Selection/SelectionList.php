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
	private array $tagEntryIndexes = [];

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
			$explicitTags = $this->inferTags($expression, [], true);

			foreach ($pendingEntries as $index => $entry) {
				if ($entry->isExplicit()) {
					continue;
				}

				if (! $this->expressionsMatch($entry->getExpression(), $expression)) {
					continue;
				}

				$pendingEntries[$index] = $entry
					->withTags($explicitTags)
					->withExplicit();
				$promoted = true;

				break;
			}

			if (! $promoted) {
				$pendingEntries[] = new SelectionItem($expression, true, $explicitTags);
			}
		}

		$this->entries = $pendingEntries;
		$this->rebuildTagIndexes();

		foreach ($incomingExpressions as $alias => $expression) {
			$this->namedExpressions[$alias] = $expression;
		}
	}

	/**
	 * @param string|list<string> $tags
	 */
	public function add(
		ValueExpressionInterface|AliasedExpression|StarExpression $expression,
		string|array $tags = [],
		bool $explicit = false,
	): SelectionItem {
		$normalizedTags = $this->inferTags($expression, $this->normalizeTags($tags), $explicit);

		foreach ($this->entries as $index => $entry) {
			if (! $this->expressionsMatch($entry->getExpression(), $expression)) {
				continue;
			}

			$newTags = array_values(array_filter(
				$normalizedTags,
				static fn (string $tag): bool => ! $entry->hasTag($tag),
			));
			$updated = $entry->withTags($normalizedTags);

			if ($explicit) {
				$updated = $updated->withExplicit();
			}

			$this->entries[$index] = $updated;
			$this->registerTagIndexes($index, $newTags);

			return $updated;
		}

		if ($expression instanceof AliasedExpression && isset($this->namedExpressions[$expression->getAlias()])) {
			throw new InvalidArgumentException(sprintf("Query expression alias '%s' is already selected.", $expression->getAlias()));
		}

		$item = new SelectionItem($expression, $explicit, $normalizedTags);
		$this->appendItem($item, $normalizedTags);

		return $item;
	}

	/**
	 * @param string|list<string> $tags
	 */
	public function tag(
		ValueExpressionInterface|AliasedExpression|StarExpression|string $selection,
		string|array $tags,
	): SelectionItem {
		$normalizedTags = $this->normalizeTags($tags);

		if ($normalizedTags === []) {
			throw new InvalidArgumentException('Selection tagging requires at least one non-empty tag.');
		}

		foreach ($this->entries as $index => $entry) {
			if (! $this->selectionMatches($entry, $selection)) {
				continue;
			}

			$newTags = array_values(array_filter(
				$normalizedTags,
				static fn (string $tag): bool => ! $entry->hasTag($tag),
			));
			$updated = $entry->withTags($normalizedTags);
			$this->entries[$index] = $updated;
			$this->registerTagIndexes($index, $newTags);

			return $updated;
		}

		throw new InvalidArgumentException('Cannot tag a selection that is not present in the list.');
	}

	public function require(ValueExpressionInterface|AliasedExpression|StarExpression $expression, string $tag): void
	{
		$this->add($expression, $tag);
	}

	public function merge(self $other, ?bool $explicit = null): void
	{
		foreach ($other->getAll() as $selection) {
			$this->add(
				$selection->getExpression(),
				$selection->getTags(),
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
				$entry->getTags(),
				$entry->isExplicit(),
			);
		}

		return $projected;
	}

	public function ensureField(FieldRef|SourceFieldExpression $field, string $tag): SelectionItem
	{
		return $this->add($field, $tag);
	}

	public function ensureInternalField(FieldRef|SourceFieldExpression $field): SelectionItem
	{
		foreach ($this->entries as $entry) {
			if ($entry->getSelectionKey() !== $field->getSelectionKey()) {
				continue;
			}

			if ($entry->isExplicit() || $entry->getExpression() instanceof AliasedExpression) {
				return $this->add($entry->getExpression(), SelectionTag::INTERNAL, $entry->isExplicit());
			}

			break;
		}

		return $this->add($field->as($field->getSelectionKey()), SelectionTag::INTERNAL);
	}

	public function ensureInternalExpression(ValueExpressionInterface $expression, string $alias): SelectionItem
	{
		return $this->add($expression->as($alias), [SelectionTag::INTERNAL, SelectionTag::SQL_ONLY]);
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
	public function getByTag(string $tag): array
	{
		$tag = trim($tag);

		if ($tag === '') {
			throw new InvalidArgumentException('Selection tag lookups require a non-empty string.');
		}

		return $this->getItemsInTagOrder($tag);
	}

	public function filterByTag(string $tag): self
	{
		$filtered = new self();

		foreach ($this->getByTag($tag) as $selection) {
			$filtered->appendItem($selection, $selection->getTags());
		}

		return $filtered;
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

		foreach ($this->tagEntryIndexes as $tag => $indexes) {
			foreach ($indexes as $index) {
				if (! isset($indexMap[$index])) {
					continue;
				}

				$filtered->registerTagIndex($tag, $indexMap[$index]);
			}
		}

		return $filtered;
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
	 * @param list<string> $tags
	 */
	private function appendItem(SelectionItem $item, array $tags = []): void
	{
		$this->entries[] = $item;
		$index = array_key_last($this->entries);

		$expression = $item->getExpression();

		if ($expression instanceof AliasedExpression) {
			$this->namedExpressions[$expression->getAlias()] = $expression->getExpression();
		}

		if (is_int($index)) {
			$this->registerTagIndexes($index, $tags);
		}
	}

	/**
	 * @param string|list<string> $tags
	 * @return list<string>
	 */
	private function normalizeTags(string|array $tags): array
	{
		if (is_string($tags)) {
			$tags = trim($tags);

			return $tags === '' ? [] : [$tags];
		}

		return array_values(array_filter(array_map(
			static fn (string $tag): string => trim($tag),
			$tags,
		), static fn (string $tag): bool => $tag !== ''));
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
	 * @param list<string> $tags
	 */
	private function registerTagIndexes(int $index, array $tags): void
	{
		foreach ($tags as $tag) {
			$this->registerTagIndex($tag, $index);
		}
	}

	private function registerTagIndex(string $tag, int $index): void
	{
		$this->tagEntryIndexes[$tag] ??= [];

		if (in_array($index, $this->tagEntryIndexes[$tag], true)) {
			return;
		}

		$this->tagEntryIndexes[$tag][] = $index;
	}

	private function rebuildTagIndexes(): void
	{
		$this->tagEntryIndexes = [];

		foreach ($this->entries as $index => $entry) {
			$this->registerTagIndexes($index, $entry->getTags());
		}
	}

	/**
	 * @param list<string> $callerTags
	 * @return list<string>
	 */
	private function inferTags(
		ValueExpressionInterface|AliasedExpression|StarExpression $expression,
		array $callerTags,
		bool $explicit,
	): array {
		$inferred = $callerTags;

		if ($explicit) {
			$inferred[] = SelectionTag::COLUMN;
			$inferred[] = SelectionTag::PUBLIC;
		}

		if (
			$this->isFieldLike($expression)
			&& ! in_array(SelectionTag::SQL_ONLY, $callerTags, true)
		) {
			$inferred[] = SelectionTag::COLUMN;
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
	private function getItemsInTagOrder(string $tag): array
	{
		$items = [];

		foreach ($this->tagEntryIndexes[$tag] ?? [] as $index) {
			$item = $this->entries[$index] ?? null;

			if (! $item instanceof SelectionItem) {
				continue;
			}

			$items[] = $item;
		}

		return $items;
	}
}
