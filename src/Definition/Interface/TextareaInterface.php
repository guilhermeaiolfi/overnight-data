<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

class TextareaInterface extends AbstractInterface
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'trim' => false,
			'placeholder' => null,
			'limit' => 255,
		]);
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

	public function trim(bool $trim): self
	{
		$this->set('trim', $trim);

		return $this;
	}

	public function getTrim(): bool
	{
		return (bool) $this->get('trim');
	}

	public function limit(int $limit): self
	{
		$this->set('limit', $limit);

		return $this;
	}

	public function getLimit(): int
	{
		return (int) $this->get('limit');
	}
}
