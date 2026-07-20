<?php

declare(strict_types=1);

namespace ON\Data\Definition\Field\Generator;

use ON\Data\ORM\Persistence\CommandResult;

/**
 * Declares that the database owns the value (identity / sequence / DB default).
 * Adapters fill {@see CommandResult} generated values;
 * this class does not call the database itself.
 */
final class DatabaseGenerator implements FieldGeneratorInterface, GeneratorDefinitionArgInterface
{
	public function __construct(
		private readonly ?string $sequence = null,
	) {
	}

	public function getSequence(): ?string
	{
		return $this->sequence;
	}

	public function getDefinitionArg(): mixed
	{
		return $this->sequence;
	}
}
