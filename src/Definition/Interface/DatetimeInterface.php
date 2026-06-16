<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

class DatetimeInterface extends AbstractInterface
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'include_seconds' => false,
			'use24hformat' => false,
		]);
	}

	public function use24hFormat(bool $use24hformat): self
	{
		$this->set('use24hformat', $use24hformat);

		return $this;
	}

	public function is24hFormat(): bool
	{
		return (bool) $this->get('use24hformat');
	}

	public function includeSeconds(bool $include_seconds): self
	{
		$this->set('include_seconds', $include_seconds);

		return $this;
	}

	public function hasSeconds(): bool
	{
		return (bool) $this->get('include_seconds');
	}
}
