<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

class ColorInterface extends AbstractInterface
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'presets' => [],
			'opacity' => false,
		]);
	}

	public function presets(array $presets): self
	{
		$this->set('presets', $presets);

		return $this;
	}

	public function getPresets(): array
	{
		$value = $this->get('presets');

		return is_array($value) ? $value : [];
	}

	public function opacity(bool $opacity): self
	{
		$this->set('opacity', $opacity);

		return $this;
	}

	public function getOpacity(): bool
	{
		return (bool) $this->get('opacity');
	}
}
