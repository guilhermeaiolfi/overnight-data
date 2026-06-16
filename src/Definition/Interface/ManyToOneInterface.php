<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

class ManyToOneInterface extends AbstractInterface
{
	// can show fields from the target collection, like {{title}}
	protected ?string $template = null;

	protected bool $allow_creation = false;

	protected bool $allow_selection = false;

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
