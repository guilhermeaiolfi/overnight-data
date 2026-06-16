<?php

declare(strict_types=1);

namespace ON\Data\Definition\Display;

class FormattedJSONDisplay extends RawDisplay
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'template' => null,
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
