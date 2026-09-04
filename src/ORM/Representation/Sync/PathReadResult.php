<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\ORM\Exception\SyncException;

/** @template-covariant TValue */
final class PathReadResult
{
	private function __construct(
		private readonly PathReadStatus $status,
		private readonly mixed $value = null,
		private readonly ?string $error = null,
	) {
	}

	/**
	 * @template TPresent
	 * @param TPresent $value
	 * @return self<TPresent>
	 */
	public static function present(mixed $value): self
	{
		return new self(PathReadStatus::Present, $value);
	}

	/** @return self<never> */
	public static function missing(string $error): self
	{
		return new self(PathReadStatus::Missing, error: $error);
	}

	/** @return self<never> */
	public static function invalid(string $error): self
	{
		return new self(PathReadStatus::Invalid, error: $error);
	}

	public function getStatus(): PathReadStatus
	{
		return $this->status;
	}

	public function isPresent(): bool
	{
		return $this->status === PathReadStatus::Present;
	}

	public function isMissing(): bool
	{
		return $this->status === PathReadStatus::Missing;
	}

	public function isInvalid(): bool
	{
		return $this->status === PathReadStatus::Invalid;
	}

	/** @return TValue */
	public function requireValue(): mixed
	{
		if (! $this->isPresent()) {
			throw new SyncException($this->error ?? 'Cannot read representation path.');
		}

		return $this->value;
	}
}
