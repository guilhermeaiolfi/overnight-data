<?php

declare(strict_types=1);

namespace ON\Data\Definition\Display;

class LabelsDisplay extends RawDisplay
{
	protected bool $formatEachLabel = true;

	public function formatEachLabel(bool $formatEachLabel): self
	{
		$this->formatEachLabel = $formatEachLabel;

		return $this;
	}

	public function isFormatEachLabel(): ?bool
	{
		return $this->formatEachLabel;
	}
}
