<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

// TODO: not implemented yet
// Show a location on a OpenStreetMap
class MapInterface extends AbstractInterface
{
	// TODO: how is point stored?
	protected ?string $default_view = null;

	public function defaultView(string $default_view): self
	{
		$this->default_view = $default_view;

		return $this;
	}

	public function getDefaultView(): ?string
	{
		return $this->default_view;
	}
}
