<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

class TextareaInterface extends AbstractInterface
{
	protected bool $trim = false;

	protected ?string $placeholder = null;

	protected int $limit = 255;

	public function placeholder(array $placeholder): self
	{
		$this->placeholder = $placeholder;

		return $this;
	}

	public function getPlaceholder(): ?string
	{
		return $this->placeholder;
	}

	public function trim(bool $trim): self
	{
		$this->trim = $trim;

		return $this;
	}

	public function getTrim(): bool
	{
		return $this->trim;
	}

	public function limit(int $limit): self
	{
		$this->limit = $limit;

		return $this;
	}

	public function getLimit(): int
	{
		return $this->limit;
	}
}
