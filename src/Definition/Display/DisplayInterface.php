<?php

declare(strict_types=1);

namespace ON\Data\Definition\Display;

interface DisplayInterface
{
	public function type(string $type): self;

	public function getType(): string;

	public function setOptions(array $options): self;

	public function getOptions(): array;

	public function end(): mixed;
}
