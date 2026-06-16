<?php

declare(strict_types=1);

namespace ON\Data\Definition\Display;

class BooleanDisplay extends RawDisplay
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'labelOn' => null,
			'labelOff' => null,
			'iconOn' => null,
			'iconOff' => null,
			'colorOn' => null,
			'colorOff' => null,
		]);
	}

	public function labelOn(string $label): self
	{
		$this->set('labelOn', $label);

		return $this;
	}

	public function getLabelOn(): ?string
	{
		return is_string($this->get('labelOn')) ? $this->get('labelOn') : null;
	}

	public function labelOff(string $label): self
	{
		$this->set('labelOff', $label);

		return $this;
	}

	public function getLabelOff(): ?string
	{
		return is_string($this->get('labelOff')) ? $this->get('labelOff') : null;
	}

	public function iconOn(string $icon): self
	{
		$this->set('iconOn', $icon);

		return $this;
	}

	public function getIconOn(): ?string
	{
		return is_string($this->get('iconOn')) ? $this->get('iconOn') : null;
	}

	public function iconOff(string $icon): self
	{
		$this->set('iconOff', $icon);

		return $this;
	}

	public function getIconOff(): ?string
	{
		return is_string($this->get('iconOff')) ? $this->get('iconOff') : null;
	}

	public function colorOn(string $color): self
	{
		$this->set('colorOn', $color);

		return $this;
	}

	public function getColorOn(): ?string
	{
		return is_string($this->get('colorOn')) ? $this->get('colorOn') : null;
	}

	public function colorOff(string $color): self
	{
		$this->set('colorOff', $color);

		return $this;
	}

	public function getColorOff(): ?string
	{
		return is_string($this->get('colorOff')) ? $this->get('colorOff') : null;
	}
}
