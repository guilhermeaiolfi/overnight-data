<?php

declare(strict_types=1);

namespace ON\Data\Definition\Display;

class FormattedDisplay extends RawDisplay
{
	protected ?string $color = null;
	protected ?string $font = null;
	protected ?bool $italic = null;
	protected ?bool $bold = null;
	protected ?string $prefix = null;
	protected ?string $suffix = null;
	protected ?string $background = null;
	protected ?string $icon = null;

	public function color(string $color): self
	{
		$this->color = $color;

		return $this;
	}

	public function getColor(): ?string
	{
		return $this->color;
	}

	public function font(string $font): self
	{
		$this->font = $font;

		return $this;
	}

	public function getFont(): ?string
	{
		return $this->font;
	}

	public function italic(bool $italic): self
	{
		$this->italic = $italic;

		return $this;
	}

	public function getItalic(): ?bool
	{
		return $this->italic;
	}

	public function bold(bool $bold): self
	{
		$this->bold = $bold;

		return $this;
	}

	public function getBold(): ?bool
	{
		return $this->bold;
	}

	public function prefix(string $prefix): self
	{
		$this->prefix = $prefix;

		return $this;
	}

	public function getPrefix(): ?string
	{
		return $this->prefix;
	}

	public function suffix(string $suffix): self
	{
		$this->suffix = $suffix;

		return $this;
	}

	public function getSuffix(): ?string
	{
		return $this->suffix;
	}

	public function background(string $background): self
	{
		$this->background = $background;

		return $this;
	}

	public function getBackground(): ?string
	{
		return $this->background;
	}

	public function icon(string $icon): self
	{
		$this->icon = $icon;

		return $this;
	}

	public function getIcon(): ?string
	{
		return $this->icon;
	}
}
