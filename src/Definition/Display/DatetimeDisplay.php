<?php

declare(strict_types=1);

namespace ON\Data\Definition\Display;

class DatetimeDisplay extends RawDisplay
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'format' => 'long',
		]);
	}

	public function format(string $format): self
	{
		$this->set('format', $format);

		return $this;
	}

	public function getFormat(): string
	{
		return (string) $this->get('format');
	}
}
