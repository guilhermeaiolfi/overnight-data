<?php

declare(strict_types=1);

namespace ON\Data\Definition\Display;

class FormattedJSONDisplay extends RawDisplay
{
	// something like: {{title}}
	protected ?string $template = null;

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
