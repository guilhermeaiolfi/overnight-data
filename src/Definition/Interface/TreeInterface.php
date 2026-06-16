<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

class TreeInterface extends AbstractInterface
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'template' => null,
			'allow_creation' => false,
			'allow_selection' => false,
		]);
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
