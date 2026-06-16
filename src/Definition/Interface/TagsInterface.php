<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

class TagsInterface extends AbstractInterface
{
	public const WHITESPACE_REMOVE = 1;
	public const WHITESPACE_REPLACE_WITH_HYPHEN = 2;
	public const WHITESPACE_REPLACE_WITH_UNDERSCORE = 3;

	public const CAPITALIZATION_CONVERT_UPPERCASE = 1;
	public const CAPITALIZATION_CONVERT_LOWERCASE = 2;

	protected array $preset_tags = [];

	protected bool $allow_other = false;

	protected bool $az = false;

	protected ?int $whitespace = null;

	protected ?int $capitalization = null;

	protected ?string $placeholder = null;

	public function whitespace(int $whitespace): self
	{
		$this->whitespace = $whitespace;

		return $this;
	}

	public function getWhitespace(): ?int
	{
		return $this->whitespace;
	}

	public function capitalization(int $capitalization): self
	{
		$this->capitalization = $capitalization;

		return $this;
	}

	public function getCapitalization(): ?int
	{
		return $this->capitalization;
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

	public function az(bool $az): self
	{
		$this->az = $az;

		return $this;
	}

	public function isAZ(): bool
	{
		return $this->az;
	}

	public function presetTags(array $tags): self
	{
		$this->preset_tags = $tags;

		return $this;
	}

	public function getPresetTags(): ?array
	{
		return $this->preset_tags;
	}

	public function placeholder(string $placeholder): self
	{
		$this->placeholder = $placeholder;

		return $this;
	}

	public function getplaceholder(): ?string
	{
		return $this->placeholder;
	}
}
