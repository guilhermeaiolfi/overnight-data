<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

interface InterfaceInterface
{
	public function setOptions(array $options): self;

	public function getOptions(): array;

	public function end(): mixed;
}
