<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolution;

use ON\Data\Mapper\FieldTypeInterface;

interface LeafNodeResolutionInterface
{
	public function getName(): string;

	/**
	 * @return class-string<FieldTypeInterface>|non-empty-string|null
	 */
	public function getType(): ?string;

	public function isNullable(): bool;
}
