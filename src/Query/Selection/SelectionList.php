<?php

declare(strict_types=1);

namespace ON\Data\Query\Selection;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use ON\Data\Query\DerivedOutputColumns;
use ON\Data\Query\DerivedSelectQuery;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\SelectQuery;
use ON\Data\Query\SourceMap;
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

			foreach ($pendingEntries as $index => $entry) {
				if ($entry->isExplicit()) {
					continue;
				}

				if (! $this->expressionsMatch($entry->getExpression(), $expression)) {
					continue;
				}

				$pendingEntries[$index] = $entry->withTag(SelectionTag::EXPLICIT);
				$promoted = true;

				break;
			}

			if (! $promoted) {
				$pendingEntries[] = new SelectionItem($expression, [SelectionTag::EXPLICIT]);
			}
		}

		$this->entries = $pendingEntries;
		$this->rebuildNamedExpressions();

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
	): SelectionItem {
		$normalizedTags = $this->normalizeTags($tags);

		foreach ($this->entries as $index => $entry) {
			if (! $this->expressionsMatch($entry->getExpression(), $expression)) {
				continue;
			}

			$updated = $entry->withTags($normalizedTags);
			$this->entries[$index] = $updated;

			return $updated;
		}

		if ($expression instanceof AliasedExpression && isset($this->namedExpressions[$expression->getAlias()])) {
			throw new InvalidArgumentException(sprintf("Query expression alias '%s' is already selected.", $expression->getAlias()));
		}

		$item = new SelectionItem($expression, $normalizedTags);
		$this->appendItem($item);

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

			$updated = $entry->withTags($normalizedTags);
			$this->entries[$index] = $updated;

			return $updated;
		}

		throw new InvalidArgumentException('Cannot tag a selection that is not present in the list.');
	}

	public function require(ValueExpressionInterface|AliasedExpression|StarExpression $expression, string $tag): void
	{
		$this->add($expression, $tag);
	}

	public function merge(self $other, ?bool $forceExplicit = null): void
	{
		foreach ($other->getAll() as $selection) {
			$tags = $selection->getTags();

			if ($forceExplicit === true && ! in_array(SelectionTag::EXPLICIT, $tags, true)) {
				$tags[] = SelectionTag::EXPLICIT;
			} elseif ($forceExplicit === false) {
				$tags = array_values(array_filter(
					$tags,
					static fn (string $tag): bool => $tag !== SelectionTag::EXPLICIT,
				));
			}

			$this->add($selection->getExpression(), $tags);
		}
	}

	public function projectTo(SourceMap $sources): self
	{
		$projected = new self();

		foreach ($this->entries as $entry) {
			$projected->add(
				$entry->getProjectedExpression($sources),
				$entry->getTags(),
			);
		}

		return $projected;
	}

	/**
	 * Project selected columns out of a derived source.
	 *
	 * The outer query may only reference the derived query's selection keys; it
	 * must not inherit joins or relation sources from the inner query.
	 */
	public function projectDerivedTo(DerivedSelectQuery $derived, SelectQuery $target): self
	{
		if ($target->getFrom() !== $derived) {
			throw new InvalidArgumentException('Derived projection targets must select from the supplied derived query.');
		}

		$projected = new self();

		foreach ($this->entries as $entry) {
			if ($entry->getExpression() instanceof StarExpression) {
				continue;
			}

			foreach (DerivedOutputColumns::namesForSelection($entry) as $outputName) {
				$projected->add(
					$target->field($outputName)->as($outputName),
					$entry->getTags(),
				);
			}
		}

		return $projected;
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
	public function getByTag(string $tag): array
	{
		$tag = trim($tag);

		if ($tag === '') {
			throw new InvalidArgumentException('Selection tag lookups require a non-empty string.');
		}

		$matches = [];

		foreach ($this->entries as $index => $selection) {
			$tagPosition = array_search($tag, $selection->getTags(), true);

			if ($tagPosition === false) {
				continue;
			}

			$matches[] = [
				'selection' => $selection,
				'tagPosition' => $tagPosition,
				'entryPosition' => $index,
			];
		}

		usort(
			$matches,
			static fn (array $left, array $right): int => [$left['tagPosition'], $left['entryPosition']]
				<=> [$right['tagPosition'], $right['entryPosition']],
		);

		return array_map(
			static fn (array $match): SelectionItem => $match['selection'],
			$matches,
		);
	}

	public function filterByTag(string $tag): self
	{
		$filtered = new self();

		foreach ($this->getByTag($tag) as $selection) {
			$filtered->appendItem($selection);
		}

		return $filtered;
	}

	public function removeByTag(string $tag): void
	{
		$tag = trim($tag);

		if ($tag === '') {
			throw new InvalidArgumentException('Selection tag removals require a non-empty string.');
		}

		$this->entries = array_values(array_filter(
			$this->entries,
			static fn (SelectionItem $entry): bool => ! $entry->hasTag($tag),
		));
		$this->rebuildNamedExpressions();
	}

	public function clear(): void
	{
		$this->entries = [];
		$this->namedExpressions = [];
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
		return $this->findBySelectionKey($name) !== null;
	}

	public function findBySelectionKey(string $name): ?SelectionItem
	{
		foreach ($this->entries as $entry) {
			if ($entry->getSelectionKey() === $name) {
				return $entry;
			}
		}

		return null;
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

	private function appendItem(SelectionItem $item): void
	{
		$this->entries[] = $item;
		$expression = $item->getExpression();

		if ($expression instanceof AliasedExpression) {
			$this->namedExpressions[$expression->getAlias()] = $expression->getExpression();
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

	private function rebuildNamedExpressions(): void
	{
		$this->namedExpressions = [];

		foreach ($this->entries as $entry) {
			$expression = $entry->getExpression();
			if ($expression instanceof AliasedExpression) {
				$this->namedExpressions[$expression->getAlias()] = $expression->getExpression();
			}
		}
	}
}
