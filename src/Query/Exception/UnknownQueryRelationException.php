<?php

declare(strict_types=1);

namespace ON\Data\Query\Exception;

use InvalidArgumentException;

final class UnknownQueryRelationException extends InvalidArgumentException
{
	public static function forDefinition(string $relationName, ?string $definitionName = null): self
	{
		$message = sprintf("Unknown query relation '%s'", $relationName);

		if ($definitionName !== null && $definitionName !== '') {
			$message .= sprintf(" on definition '%s'", $definitionName);
		}

		return new self($message . '.');
	}
}
