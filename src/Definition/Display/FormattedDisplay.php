<?php

declare(strict_types=1);

namespace ON\Data\Definition\Display;

class FormattedDisplay extends RawDisplay
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'color' => null,
			'font' => null,
			'italic' => null,
			'bold' => null,
			'prefix' => null,
			'suffix' => null,
			'background' => null,
			'icon' => null,
		]);
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

	public function font(string $font): self
	{
		$this->set('font', $font);

		return $this;
	}

	public function getFont(): ?string
	{
		return is_string($this->get('font')) ? $this->get('font') : null;
	}

	public function italic(bool $italic): self
	{
		$this->set('italic', $italic);

		return $this;
	}

	public function getItalic(): ?bool
	{
		$value = $this->get('italic');

		return is_bool($value) ? $value : null;
	}

	public function bold(bool $bold): self
	{
		$this->set('bold', $bold);

		return $this;
	}

	public function getBold(): ?bool
	{
		$value = $this->get('bold');

		return is_bool($value) ? $value : null;
	}

	public function prefix(string $prefix): self
	{
		$this->set('prefix', $prefix);

		return $this;
	}

	public function getPrefix(): ?string
	{
		return is_string($this->get('prefix')) ? $this->get('prefix') : null;
	}

	public function suffix(string $suffix): self
	{
		$this->set('suffix', $suffix);

		return $this;
	}

	public function getSuffix(): ?string
	{
		return is_string($this->get('suffix')) ? $this->get('suffix') : null;
	}

	public function background(string $background): self
	{
		$this->set('background', $background);

		return $this;
	}

	public function getBackground(): ?string
	{
		return is_string($this->get('background')) ? $this->get('background') : null;
	}

	public function icon(string $icon): self
	{
		$this->set('icon', $icon);

		return $this;
	}

	public function getIcon(): ?string
	{
		return is_string($this->get('icon')) ? $this->get('icon') : null;
	}
}
