<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

class DropdownMultipleInterface extends AbstractInterface
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'placeholder' => null,
			'icon' => null,
			'choices' => [],
			'allow_other' => true,
			'allow_none' => true,
		]);
	}

	public function choices(array $choices): self
	{
		$this->set('choices', $choices);

		return $this;
	}

	public function getChoices(): array
	{
		$value = $this->get('choices');

		return is_array($value) ? $value : [];
	}

	public function allowOther(bool $allow_other): self
	{
		$this->set('allow_other', $allow_other);

		return $this;
	}

	public function isAllowOther(): bool
	{
		return (bool) $this->get('allow_other');
	}

	public function allowNone(bool $allow_none): self
	{
		$this->set('allow_none', $allow_none);

		return $this;
	}

	public function isAllowNone(): bool
	{
		return (bool) $this->get('allow_none');
	}

	public function placeholder(array $placeholder): self
	{
		$this->set('placeholder', $placeholder);

		return $this;
	}

	public function getPlaceholder(): ?string
	{
		return is_string($this->get('placeholder')) ? $this->get('placeholder') : null;
	}

	public function icon(array $icon): self
	{
		$this->set('icon', $icon);

		return $this;
	}

	public function getIcon(): ?string
	{
		return is_string($this->get('icon')) ? $this->get('icon') : null;
	}
}
