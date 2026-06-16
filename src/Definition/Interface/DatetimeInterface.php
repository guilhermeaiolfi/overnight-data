<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

class DatetimeInterface extends AbstractInterface
{
	protected bool $include_seconds = false;

	protected bool $use24hformat = false;

	public function use24hFormat(bool $use24hformat): self
	{
		$this->use24hformat = $use24hformat;

		return $this;
	}

	public function is24hFormat(): bool
	{
		return $this->use24hformat;
	}

	public function includeSeconds(bool $include_seconds): self
	{
		$this->include_seconds = $include_seconds;

		return $this;
	}

	public function hasSeconds(): bool
	{
		return $this->include_seconds;
	}
}
