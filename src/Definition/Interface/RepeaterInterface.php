<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

class RepeaterInterface extends AbstractInterface
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'template' => null,
			'create_new_label' => null,
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
