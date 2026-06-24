<?php

declare(strict_types=1);

namespace ON\Data\Database\Cycle;

use ON\Data\Definition\Field\FieldInterface;

/**
 * @internal
 */
final class CycleResultColumn
{
	public function __construct(
		private readonly string $backendName,
		private readonly string $logicalName,
		private readonly ?FieldInterface $field = null,
	) {
	}

	public function backendName(): string
	{
		return $this->backendName;
	}

	public function logicalName(): string
	{
		return $this->logicalName;
	}

	public function field(): ?FieldInterface
	{
		return $this->field;
	}
}
