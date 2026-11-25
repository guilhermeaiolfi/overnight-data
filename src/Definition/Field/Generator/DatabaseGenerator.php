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

	public static function sequenceFromArgument(mixed $arg): ?string
	{
		if (is_string($arg) && $arg !== '') {
			return $arg;
		}

		if (is_array($arg) && isset($arg['sequence']) && is_string($arg['sequence']) && $arg['sequence'] !== '') {
			return $arg['sequence'];
		}

		return null;
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
