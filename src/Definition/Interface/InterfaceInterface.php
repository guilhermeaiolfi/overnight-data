<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Relation\RelationInterface;

interface InterfaceInterface
{
	public function setOptions(array $options): self;

	public function getOptions(): array;

	/** @return RelationInterface|FieldInterface */
	public function end(): mixed;
}
