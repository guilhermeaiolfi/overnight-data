<?php

declare(strict_types=1);

namespace ON\Data\Definition\Display;

class IconDisplay extends RawDisplay
{
	protected bool $filled = false;

	protected ?string $color = null;

	public function filled(bool $filled): self
	{
		$this->filled = $filled;

		return $this;
	}

	public function isFilled(): ?bool
	{
		return $this->filled;
	}

	public function color(string $color): self
	{
		$this->color = $color;

		return $this;
	}

	public function getColor(): ?string
	{
		return $this->color;
	}
}
