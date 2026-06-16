<?php

declare(strict_types=1);

namespace ON\Data\Definition\Display;

class ImageDisplay extends RawDisplay
{
	protected bool $displayAsCircle = false;

	public function displayAsCircle(bool $displayAsCircle): self
	{
		$this->displayAsCircle = $displayAsCircle;

		return $this;
	}

	public function shouldDisplayAsCircle(): bool
	{
		return $this->displayAsCircle;
	}
}
