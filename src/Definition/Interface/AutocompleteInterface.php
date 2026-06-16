<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

class AutocompleteInterface extends AbstractInterface
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'trigger' => 'throttle',
			'rate' => 500,
			'placeholder' => null,
			'url' => null,
			'text_path' => null,
			'value_path' => null,
			'result_path' => null,
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

	public function trigger(bool $trigger): self
	{
		$this->set('trigger', $trigger);

		return $this;
	}

	public function getTrigger(): string
	{
		return (string) $this->get('trigger');
	}

	public function rate(int $rate): self
	{
		$this->set('rate', $rate);

		return $this;
	}

	public function getRate(): int
	{
		return (int) $this->get('rate');
	}

	public function url(int $url): self
	{
		$this->set('url', $url);

		return $this;
	}

	public function getUrl(): ?string
	{
		return is_string($this->get('url')) ? $this->get('url') : null;
	}

	public function textPath(int $text_path): self
	{
		$this->set('text_path', $text_path);

		return $this;
	}

	public function getTextPath(): ?string
	{
		return is_string($this->get('text_path')) ? $this->get('text_path') : null;
	}

	public function valuePath(int $value_path): self
	{
		$this->set('value_path', $value_path);

		return $this;
	}

	public function getValuePath(): ?string
	{
		return is_string($this->get('value_path')) ? $this->get('value_path') : null;
	}

	public function resultPath(int $result_path): self
	{
		$this->set('result_path', $result_path);

		return $this;
	}

	public function getResultPath(): ?string
	{
		return is_string($this->get('result_path')) ? $this->get('result_path') : null;
	}
}
