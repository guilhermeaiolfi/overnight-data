<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

class AutocompleteInterface extends AbstractInterface
{
	// throttle || debounce
	protected string $trigger = "throttle";
	// ms
	protected int $rate = 500;


	// https://example.com/search?q={{value}}
	protected ?string $placeholder = null;
	protected ?string $url = null;
	protected ?string $text_path = null;
	protected ?string $value_path = null;
	protected ?string $result_path = null;

	public function placeholder(array $placeholder): self
	{
		$this->placeholder = $placeholder;

		return $this;
	}

	public function getPlaceholder(): ?string
	{
		return $this->placeholder;
	}

	public function trigger(bool $trigger): self
	{
		$this->trigger = $trigger;

		return $this;
	}

	public function getTrigger(): string
	{
		return $this->trigger;
	}

	public function rate(int $rate): self
	{
		$this->rate = $rate;

		return $this;
	}

	public function getRate(): int
	{
		return $this->rate;
	}

	public function url(int $url): self
	{
		$this->url = $url;

		return $this;
	}

	public function getUrl(): ?string
	{
		return $this->url;
	}

	public function textPath(int $text_path): self
	{
		$this->text_path = $text_path;

		return $this;
	}

	public function getTextPath(): ?string
	{
		return $this->text_path;
	}

	public function valuePath(int $value_path): self
	{
		$this->value_path = $value_path;

		return $this;
	}

	public function getValuePath(): ?string
	{
		return $this->value_path;
	}

	public function resultPath(int $result_path): self
	{
		$this->result_path = $result_path;

		return $this;
	}

	public function getResultPath(): ?string
	{
		return $this->result_path;
	}
}
