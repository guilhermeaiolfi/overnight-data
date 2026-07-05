<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

final class ExpectedAffectedRows
{
	private function __construct(
		private ?int $exact,
		private bool $zeroOrOne,
	) {
	}

	public static function exactly(int $rows): self
	{
		return new self($rows, false);
	}

	public static function zeroOrOne(): self
	{
		return new self(null, true);
	}

	public function accepts(int $affectedRows): bool
	{
		if ($this->zeroOrOne) {
			return $affectedRows === 0 || $affectedRows === 1;
		}

		return $affectedRows === $this->exact;
	}

	public function describe(): string
	{
		if ($this->zeroOrOne) {
			return '0 or 1 rows';
		}

		return sprintf('%d row%s', $this->exact, $this->exact === 1 ? '' : 's');
	}
}
