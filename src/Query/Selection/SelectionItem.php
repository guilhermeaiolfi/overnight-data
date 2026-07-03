<?php

declare(strict_types=1);

namespace ON\Data\Query\Selection;

use InvalidArgumentException;
use LogicException;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\SourceFieldExpression;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\SelectQuery;

final class SelectionItem
{
	/**
	 * @param list<string> $tags
	 */
	private readonly array $tags;

	private readonly ?string $selectionKey;

	public function __construct(
		private readonly ValueExpressionInterface|AliasedExpression|StarExpression $expression,
		private readonly bool $explicit = false,
		array $tags = [],
	) {
		try {
			$this->selectionKey = $expression->getSelectionKey();
		} catch (LogicException) {
			$this->selectionKey = null;
		}
		$this->tags = array_values(array_unique(
			array_map($this->normalizeTag(...), $tags),
		));
	}

	public function getExpression(): ValueExpressionInterface|AliasedExpression|StarExpression
	{
		return $this->expression;
	}

	public function getSelectionKey(): string
	{
		return $this->selectionKey ?? $this->expression->getSelectionKey();
	}

	public function getProjectedExpression(
		?QuerySourceInterface $from = null,
		?QuerySourceInterface $to = null,
	): ValueExpressionInterface|AliasedExpression|StarExpression {
		$expression = $this->expression instanceof AliasedExpression
			? $this->expression->getExpression()
			: $this->expression;

		if ($from !== null && $to !== null) {
			if ($from instanceof SelectQuery && $from->hasAlias()) {
				$expression = $expression instanceof StarExpression
					? $from->all()
					: $from->field($this->getSelectionKey());
			} else {
				$expression = $expression->bindTo($to, from: $from);
			}
		}

		if ($expression instanceof StarExpression) {
			return $expression;
		}

		if ($this->expression instanceof AliasedExpression) {
			return $expression === $this->expression->getExpression()
				? $this->expression
				: $expression->as($this->getSelectionKey());
		}

		return $expression->as($this->getSelectionKey());
	}

	public function isExplicit(): bool
	{
		return $this->explicit;
	}

	public function isImplicit(): bool
	{
		return ! $this->isExplicit();
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
		$tag = $this->normalizeTag($tag);

		return in_array($tag, $this->tags, true);
	}

	public function withExplicit(): self
	{
		if ($this->explicit) {
			return $this;
		}

		return new self($this->expression, true, $this->tags);
	}

	public function withTag(string $tag): self
	{
		$tag = $this->normalizeTag($tag);

		if (in_array($tag, $this->tags, true)) {
			return $this;
		}

		return new self($this->expression, $this->explicit, [...$this->tags, $tag]);
	}

	/**
	 * @param list<string> $tags
	 */
	public function withTags(array $tags): self
	{
		$updated = $this;

		foreach ($tags as $tag) {
			$updated = $updated->withTag($tag);
		}

		return $updated;
	}

	public function hasAnyTag(string ...$tags): bool
	{
		foreach ($tags as $tag) {
			if ($this->hasTag($tag)) {
				return true;
			}
		}

		return false;
	}

	private function normalizeTag(string $tag): string
	{
		$tag = trim($tag);

		if ($tag === '') {
			throw new InvalidArgumentException('Selection tags must be non-empty strings.');
		}

		return $tag;
	}
}
