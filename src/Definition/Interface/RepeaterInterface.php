<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

// TODO: not implemented yet
// Create multi entries of the same structure
class RepeaterInterface extends AbstractInterface
{
	protected ?string $template = null;

	protected ?string $create_new_label = null;

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
