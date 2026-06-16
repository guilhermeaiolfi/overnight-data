<?php

declare(strict_types=1);

namespace ON\Data\Definition\Display;

use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Relation\RelationInterface;

interface DisplayInterface
{
	public function type(string $type): self;

	public function getType(): string;

	public function setOptions(array $options): self;

	public function getOptions(): array;

	/** @return RelationInterface|FieldInterface */
	public function end(): mixed;
}
