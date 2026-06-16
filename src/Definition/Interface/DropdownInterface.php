<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

// Allow selection of an item
class DropdownInterface extends AbstractInterface
{
	protected ?string $placeholder = null;

	protected ?string $icon = null;

	/**
	 * [
	 * 		text: '',
	 * 		value: '',
	 * 		icon: '',
	 * 		color: ''
	 * ]
	 */

	protected array $choices = [];

	protected bool $allow_other = true;

	protected bool $allow_none = true;

	public function choices(array $choices): self
	{
		$this->choices = $choices;

		return $this;
	}

	public function getChoices(): array
	{
		return $this->choices;
	}

	public function allowOther(bool $allow_other): self
	{
		$this->allow_other = $allow_other;

		return $this;
	}

	public function isAllowOther(): bool
	{
		return $this->allow_other;
	}

	public function allowNone(bool $allow_none): self
	{
		$this->allow_none = $allow_none;

		return $this;
	}

	public function isAllowNone(): bool
	{
		return $this->allow_none;
	}

	public function placeholder(array $placeholder): self
	{
		$this->placeholder = $placeholder;

		return $this;
	}

	public function getPlaceholder(): ?string
	{
		return $this->placeholder;
	}

	public function icon(array $icon): self
	{
		$this->icon = $icon;

		return $this;
	}

	public function getIcon(): ?string
	{
		return $this->icon;
	}
}
