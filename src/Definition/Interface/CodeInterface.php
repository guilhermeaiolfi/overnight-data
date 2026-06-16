<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

class CodeInterface extends AbstractInterface
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'line_numbers' => false,
			'wrapping' => false,
			'language' => null,
			'template' => null,
			'limit' => 255,
		]);
	}

	public function language(array $language): self
	{
		$this->set('language', $language);

		return $this;
	}

	public function getLanguage(): ?string
	{
		return is_string($this->get('language')) ? $this->get('language') : null;
	}

	public function wrapping(bool $wrapping): self
	{
		$this->set('wrapping', $wrapping);

		return $this;
	}

	public function isWrapping(): bool
	{
		return (bool) $this->get('wrapping');
	}

	public function showLineNumbers(bool $line_numbers): self
	{
		$this->set('line_numbers', $line_numbers);

		return $this;
	}

	public function shouldShowLineNumbers(): bool
	{
		return (bool) $this->get('line_numbers');
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

	public function template(string $template): self
	{
		$this->set('template', $template);

		return $this;
	}

	public function getTemplate(): ?string
	{
		return is_string($this->get('template')) ? $this->get('template') : null;
	}
}
