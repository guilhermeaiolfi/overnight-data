<?php

declare(strict_types=1);

namespace ON\Data\Definition\Display;

class LabelsDisplay extends RawDisplay
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'formatEachLabel' => true,
		]);
	}

	public function formatEachLabel(bool $formatEachLabel): self
	{
		$this->set('formatEachLabel', $formatEachLabel);

		return $this;
	}

	public function isFormatEachLabel(): ?bool
	{
		$value = $this->get('formatEachLabel');

		return is_bool($value) ? $value : null;
	}
}
