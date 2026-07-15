<?php

declare(strict_types=1);

namespace ON\Data\Query\Condition;

use InvalidArgumentException;

final class ConditionItem
{
	/**
	 * @param list<string> $tags
	 */
	private readonly array $tags;

	/**
	 * @param list<string> $tags
	 */
	public function __construct(
		private readonly ConditionInterface $condition,
		array $tags = [ConditionTag::USER],
	) {
		$this->tags = array_values(array_unique(
			array_map($this->normalizeTag(...), $tags),
		));

		if ($this->tags === []) {
			throw new InvalidArgumentException('Condition items require at least one tag.');
		}
	}

	public function getCondition(): ConditionInterface
	{
		return $this->condition;
	}

	/**
	 * @return list<string>
	 */
	public function getTags(): array
	{
		return $this->tags;
	}

	public function hasTag(string $tag): bool
	{
		return in_array($this->normalizeTag($tag), $this->tags, true);
	}

	/**
	 * @param list<string> $tags
	 */
	public function withTags(array $tags): self
	{
		return new self($this->condition, $tags);
	}

	public function withCondition(ConditionInterface $condition): self
	{
		return new self($condition, $this->tags);
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
