<?php

declare(strict_types=1);

namespace ON\Data\Query\Selection;

use InvalidArgumentException;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\QuerySourceInterface;

final class SelectionItem
{
	/**
	 * @param list<string> $reasons
	 */
	private readonly array $reasons;

	public function __construct(
		private readonly ValueExpressionInterface|AliasedExpression|StarExpression $expression,
		private readonly bool $explicit = false,
		array $reasons = [],
	) {
		$this->reasons = array_values(array_unique(
			array_map($this->normalizeReason(...), $reasons),
		));
	}

	public function getExpression(): ValueExpressionInterface|AliasedExpression|StarExpression
	{
		return $this->expression;
	}

	public function getSelectionKey(): string
	{
		return $this->expression->getSelectionKey();
	}

	public function getProjectedExpression(
		?QuerySourceInterface $from = null,
		?QuerySourceInterface $to = null,
	): ValueExpressionInterface|AliasedExpression|StarExpression {
		if ($this->expression instanceof StarExpression) {
			return $this->expression;
		}

		$expression = $this->expression instanceof AliasedExpression
			? $this->expression->getExpression()
			: $this->expression;

		if ($from !== null && $to !== null) {
			$expression = $expression->bindTo($to, from: $from);
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
	public function getReasons(): array
	{
		return $this->reasons;
	}

	public function hasReason(string $reason): bool
	{
		$reason = $this->normalizeReason($reason);

		return in_array($reason, $this->reasons, true);
	}

	public function withExplicit(): self
	{
		if ($this->explicit) {
			return $this;
		}

		return new self($this->expression, true, $this->reasons);
	}

	public function withReason(string $reason): self
	{
		$reason = $this->normalizeReason($reason);

		if (in_array($reason, $this->reasons, true)) {
			return $this;
		}

		return new self($this->expression, $this->explicit, [...$this->reasons, $reason]);
	}

	/**
	 * @param list<string> $reasons
	 */
	public function withReasons(array $reasons): self
	{
		$updated = $this;

		foreach ($reasons as $reason) {
			$updated = $updated->withReason($reason);
		}

		return $updated;
	}

	private function normalizeReason(string $reason): string
	{
		$reason = trim($reason);

		if ($reason === '') {
			throw new InvalidArgumentException('Selection reasons must be non-empty strings.');
		}

		return $reason;
	}
}
