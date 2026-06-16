<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

class MapInterface extends AbstractInterface
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'default_view' => null,
		]);
	}

	public function defaultView(string $default_view): self
	{
		$this->set('default_view', $default_view);

		return $this;
	}

	public function getDefaultView(): ?string
	{
		return is_string($this->get('default_view')) ? $this->get('default_view') : null;
	}
}
