<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

class CodeInterface extends AbstractInterface
{
	protected bool $line_numbers = false;

	protected bool $wrapping = false;

	protected ?string $language = null;

	protected ?string $template = null;

	protected int $limit = 255;

	public function language(array $language): self
	{
		$this->language = $language;

		return $this;
	}

	public function getLanguage(): ?string
	{
		return $this->language;
	}

	public function wrapping(bool $wrapping): self
	{
		$this->wrapping = $wrapping;

		return $this;
	}

	public function isWrapping(): bool
	{
		return $this->wrapping;
	}

	public function showLineNumbers(bool $line_numbers): self
	{
		$this->line_numbers = $line_numbers;

		return $this;
	}

	public function shouldShowLineNumbers(): bool
	{
		return $this->line_numbers;
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

	public function template(string $template): self
	{
		$this->template = $template;

		return $this;
	}

	public function getTemplate(): ?string
	{
		return $this->template;
	}
}
