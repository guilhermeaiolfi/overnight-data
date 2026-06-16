<?php

declare(strict_types=1);

namespace ON\Data\Definition\View;

use ON\Data\Definition\DefinitionInterface;

interface ViewDefinitionInterface extends DefinitionInterface
{
	public function source(string|DefinitionInterface $source): self;

	public function getSourceName(): ?string;

	public function getSource(): ?DefinitionInterface;

	public function hasSource(): bool;
}
