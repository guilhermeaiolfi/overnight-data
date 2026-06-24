<?php

declare(strict_types=1);

namespace ON\Data\Query\Exception;

use InvalidArgumentException;

final class UnknownQueryFieldException extends InvalidArgumentException
{
	public static function forDefinition(string $fieldName, ?string $definitionName = null): self
	{
		$message = sprintf("Unknown query field '%s'", $fieldName);

		if ($definitionName !== null && $definitionName !== '') {
			$message .= sprintf(" on definition '%s'", $definitionName);
		}

		return new self($message . '.');
	}
}
