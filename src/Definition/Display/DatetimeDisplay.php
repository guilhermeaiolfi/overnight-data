<?php

declare(strict_types=1);

namespace ON\Data\Definition\Display;

class DatetimeDisplay extends RawDisplay
{
	protected string $format = "long";

	public function format(string $format): self
	{
		$this->format = $format;

		return $this;
	}

	public function getFormat(): string
	{
		return $this->format;
	}
}
