<?php

declare(strict_types=1);

namespace ON\Data\Definition\Display;

class ImageDisplay extends RawDisplay
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'displayAsCircle' => false,
		]);
	}

	public function displayAsCircle(bool $displayAsCircle): self
	{
		$this->set('displayAsCircle', $displayAsCircle);

		return $this;
	}

	public function shouldDisplayAsCircle(): bool
	{
		return (bool) $this->get('displayAsCircle');
	}
}
