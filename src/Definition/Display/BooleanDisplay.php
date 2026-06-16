<?php

declare(strict_types=1);

namespace ON\Data\Definition\Display;

class BooleanDisplay extends RawDisplay
{
	protected ?string $labelOn = null;
	protected ?string $labelOff = null;

	protected ?string $iconOn = null;
	protected ?string $iconOff = null;

	protected ?string $colorOn = null;
	protected ?string $colorOff = null;

	public function labelOn(string $label): self
	{
		$this->labelOn = $label;

		return $this;
	}

	public function getLabelOn(): ?string
	{
		return $this->labelOn;
	}

	public function labelOff(string $label): self
	{
		$this->labelOff = $label;

		return $this;
	}

	public function getLabelOff(): ?string
	{
		return $this->labelOff;
	}

	public function iconOn(string $icon): self
	{
		$this->iconOn = $icon;

		return $this;
	}

	public function getIconOn(): ?string
	{
		return $this->iconOn;
	}

	public function iconOff(string $icon): self
	{
		$this->iconOff = $icon;

		return $this;
	}

	public function getIconOff(): ?string
	{
		return $this->iconOff;
	}

	public function colorOn(string $color): self
	{
		$this->colorOn = $color;

		return $this;
	}

	public function getColorOn(): ?string
	{
		return $this->colorOn;
	}

	public function colorOff(string $color): self
	{
		$this->colorOff = $color;

		return $this;
	}

	public function getColorOff(): ?string
	{
		return $this->colorOff;
	}
}
