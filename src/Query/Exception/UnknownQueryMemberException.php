<?php

declare(strict_types=1);

namespace ON\Data\Query\Exception;

use InvalidArgumentException;

final class UnknownQueryMemberException extends InvalidArgumentException
{
	public static function forDefinition(string $memberName, ?string $definitionName = null): self
	{
		$message = sprintf("Unknown query member '%s'", $memberName);

		if ($definitionName !== null && $definitionName !== '') {
			$message .= sprintf(" on definition '%s'", $definitionName);
		}

		return new self($message . '.');
	}
}
