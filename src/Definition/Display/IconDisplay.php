<?php

declare(strict_types=1);

namespace ON\Data\Definition\Display;

class IconDisplay extends RawDisplay
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'filled' => false,
			'color' => null,
		]);
	}

	public function filled(bool $filled): self
	{
		$this->set('filled', $filled);

		return $this;
	}

	public function isFilled(): ?bool
	{
		$value = $this->get('filled');

		return is_bool($value) ? $value : null;
	}

	public function color(string $color): self
	{
		$this->set('color', $color);

		return $this;
	}

	public function getColor(): ?string
	{
		return is_string($this->get('color')) ? $this->get('color') : null;
	}
}
