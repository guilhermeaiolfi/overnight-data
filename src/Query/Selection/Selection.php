<?php

declare(strict_types=1);

namespace ON\Data\Query\Selection;

use InvalidArgumentException;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;

final class Selection
{
	/**
	 * @param list<string> $reasons
	 */
	public function __construct(
		private readonly ValueExpressionInterface|AliasedExpression $expression,
		private readonly bool $explicit = false,
		private readonly array $reasons = [],
	) {
		$this->assertReasons($this->reasons);
	}

	public function getExpression(): ValueExpressionInterface|AliasedExpression
	{
		return $this->expression;
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
	private function assertReasons(array $reasons): void
	{
		foreach ($reasons as $reason) {
			$this->normalizeReason($reason);
		}
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
