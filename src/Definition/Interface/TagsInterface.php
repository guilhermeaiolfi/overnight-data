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

	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'preset_tags' => [],
			'allow_other' => false,
			'az' => false,
			'whitespace' => null,
			'capitalization' => null,
			'placeholder' => null,
		]);
	}

	public function whitespace(int $whitespace): self
	{
		$this->set('whitespace', $whitespace);

		return $this;
	}

	public function getWhitespace(): ?int
	{
		$value = $this->get('whitespace');

		return is_int($value) ? $value : null;
	}

	public function capitalization(int $capitalization): self
	{
		$this->set('capitalization', $capitalization);

		return $this;
	}

	public function getCapitalization(): ?int
	{
		$value = $this->get('capitalization');

		return is_int($value) ? $value : null;
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

	public function az(bool $az): self
	{
		$this->set('az', $az);

		return $this;
	}

	public function isAZ(): bool
	{
		return (bool) $this->get('az');
	}

	public function presetTags(array $tags): self
	{
		$this->set('preset_tags', $tags);

		return $this;
	}

	public function getPresetTags(): ?array
	{
		$value = $this->get('preset_tags');

		return is_array($value) ? $value : null;
	}

	public function placeholder(string $placeholder): self
	{
		$this->set('placeholder', $placeholder);

		return $this;
	}

	public function getplaceholder(): ?string
	{
		return is_string($this->get('placeholder')) ? $this->get('placeholder') : null;
	}
}
