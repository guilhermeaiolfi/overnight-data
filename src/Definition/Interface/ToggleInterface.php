<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

class ToggleInterface extends AbstractInterface
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'label' => null,
			'icon_on' => null,
			'icon_off' => null,
			'color_on' => null,
			'color_off' => null,
		]);
	}

	public function label(string $label): self
	{
		$this->set('label', $label);

		return $this;
	}

	public function getLabel(): ?string
	{
		return is_string($this->get('label')) ? $this->get('label') : null;
	}

	public function iconOn(bool $icon): self
	{
		$this->set('icon_on', $icon);

		return $this;
	}

	public function getIconOn(): ?string
	{
		return is_string($this->get('icon_on')) ? $this->get('icon_on') : null;
	}

	public function iconOff(bool $icon): self
	{
		$this->set('icon_off', $icon);

		return $this;
	}

	public function getIconOff(): ?string
	{
		return is_string($this->get('icon_off')) ? $this->get('icon_off') : null;
	}

	public function colorOn(bool $color): self
	{
		$this->set('color_on', $color);

		return $this;
	}

	public function getColorOn(): ?string
	{
		return is_string($this->get('color_on')) ? $this->get('color_on') : null;
	}

	public function colorOff(bool $color): self
	{
		$this->set('color_off', $color);

		return $this;
	}

	public function getColorOff(): ?string
	{
		return is_string($this->get('color_off')) ? $this->get('color_off') : null;
	}
}
