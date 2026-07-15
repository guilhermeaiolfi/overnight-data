<?php

declare(strict_types=1);

namespace ON\Data\Query\Condition;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use ON\Data\Query\QuerySourceInterface;
use Traversable;

/**
 * @implements IteratorAggregate<int, ConditionItem>
 */
final class ConditionList implements IteratorAggregate, Countable
{
	/**
	 * @var list<ConditionItem>
	 */
	private array $entries = [];

	public function add(ConditionInterface $condition, string ...$tags): void
	{
		$resolved = $tags === [] ? [ConditionTag::USER] : array_values($tags);
		$this->entries[] = new ConditionItem($condition, $resolved);
	}

	/**
	 * @param list<ConditionInterface> $conditions
	 */
	public function addAll(array $conditions, string ...$tags): void
	{
		foreach ($conditions as $condition) {
			$this->add($condition, ...$tags);
		}
	}

	/**
	 * Remove every entry with $tag, then add $conditions tagged with $tag (and optional extra tags).
	 */
	public function replaceByTag(string $tag, ConditionInterface ...$conditions): void
	{
		$tag = $this->normalizeTag($tag);
		$this->removeByTag($tag);

		foreach ($conditions as $condition) {
			$this->add($condition, $tag);
		}
	}

	public function removeByTag(string $tag): void
	{
		$tag = $this->normalizeTag($tag);
		$this->entries = array_values(array_filter(
			$this->entries,
			static fn (ConditionItem $entry): bool => ! $entry->hasTag($tag),
		));
	}

	/**
	 * @return list<ConditionInterface>
	 */
	public function getAll(): array
	{
		return array_map(
			static fn (ConditionItem $entry): ConditionInterface => $entry->getCondition(),
			$this->entries,
		);
	}

	/**
	 * @return list<ConditionItem>
	 */
	public function getItems(): array
	{
		return $this->entries;
	}

	/**
	 * @return list<ConditionItem>
	 */
	public function getByTag(string $tag): array
	{
		$tag = $this->normalizeTag($tag);

		return array_values(array_filter(
			$this->entries,
			static fn (ConditionItem $entry): bool => $entry->hasTag($tag),
		));
	}

	/**
	 * @return list<ConditionInterface>
	 */
	public function getConditionsByTag(string $tag): array
	{
		return array_map(
			static fn (ConditionItem $entry): ConditionInterface => $entry->getCondition(),
			$this->getByTag($tag),
		);
	}

	/**
	 * @param callable(ConditionItem): ConditionItem $mapper
	 */
	public function map(callable $mapper): self
	{
		$mapped = new self();

		foreach ($this->entries as $entry) {
			$mapped->entries[] = $mapper($entry);
		}

		return $mapped;
	}

	/**
	 * Rebind every condition onto $to (for SelectQuery::copy()).
	 */
	public function bindTo(QuerySourceInterface $to, QuerySourceInterface $from): self
	{
		return $this->map(
			static fn (ConditionItem $entry): ConditionItem => $entry->withCondition(
				$entry->getCondition()->bindTo($to, from: $from),
			),
		);
	}

	public function clear(): void
	{
		$this->entries = [];
	}

	public function count(): int
	{
		return count($this->entries);
	}

	/**
	 * @return Traversable<int, ConditionItem>
	 */
	public function getIterator(): Traversable
	{
		return new ArrayIterator($this->entries);
	}

	private function normalizeTag(string $tag): string
	{
		$tag = trim($tag);

		if ($tag === '') {
			throw new InvalidArgumentException('Condition tags must be non-empty strings.');
		}

		return $tag;
	}
}
