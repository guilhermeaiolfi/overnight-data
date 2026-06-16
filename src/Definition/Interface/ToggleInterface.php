<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

class ToggleInterface extends AbstractInterface
{
	protected ?string $label = null;

	protected ?string $icon_on = null;
	protected ?string $icon_off = null;

	protected ?string $color_on = null;
	protected ?string $color_off = null;

	public function label(string $label): self
	{
		$this->label = $label;

		return $this;
	}

	public function getLabel(): ?string
	{
		return $this->label;
	}

	public function iconOn(bool $icon): self
	{
		$this->icon_on = $icon;

		return $this;
	}

	public function getIconOn(): ?string
	{
		return $this->icon_on;
	}

	public function iconOff(bool $icon): self
	{
		$this->icon_off = $icon;

		return $this;
	}

	public function getIconOff(): ?string
	{
		return $this->icon_off;
	}

	public function colorOn(bool $color): self
	{
		$this->color_on = $color;

		return $this;
	}

	public function getColorOn(): ?string
	{
		return $this->color_on;
	}

	public function colorOff(bool $color): self
	{
		$this->color_off = $color;

		return $this;
	}

	public function getColorOff(): ?string
	{
		return $this->color_off;
	}
}
