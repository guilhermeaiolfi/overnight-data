<?php

declare(strict_types=1);

namespace ON\Data\ORM\History;

use DateTimeImmutable;

final readonly class FlushCommit
{
	/** @param non-empty-list<FlushChange> $changes */
	public function __construct(
		public int $sequence,
		public ?string $label,
		public array $changes,
		public DateTimeImmutable $committedAt,
	) {
	}

	public function isReversible(): bool
	{
		foreach ($this->changes as $change) {
			if (! $change->isReversible()) {
				return false;
			}
		}

		return true;
	}

	/** @return array{sequence: int, label: string|array{base64: string}|null, committedAt: string, reversible: bool, changes: list<array<string, mixed>>} */
	public function toArray(): array
	{
		return [
			'sequence' => $this->sequence,
			'label' => HistoryValue::forArray($this->label),
			'committedAt' => HistoryValue::forArray($this->committedAt),
			'reversible' => $this->isReversible(),
			'changes' => array_map(static fn (FlushChange $change): array => $change->toArray(), $this->changes),
		];
	}
}
