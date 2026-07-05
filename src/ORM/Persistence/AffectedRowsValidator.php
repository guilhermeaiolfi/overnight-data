<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\Exception\UnexpectedAffectedRowsException;

final class AffectedRowsValidator
{
	private const EXPECTED_AFFECTED_ROWS = 1;

	public function validate(CommandInterface $command, CommandResult $result): void
	{
		if (! $command instanceof InsertCommand
			&& ! $command instanceof UpdateCommand
			&& ! $command instanceof DeleteCommand
		) {
			return;
		}

		$affectedRows = $result->getAffectedRows();

		if ($affectedRows === self::EXPECTED_AFFECTED_ROWS) {
			return;
		}

		throw new UnexpectedAffectedRowsException($this->buildMessage($command, $affectedRows));
	}

	private function buildMessage(CommandInterface $command, int $affectedRows): string
	{
		$collection = $command->getCollection()->getName();

		if ($command instanceof InsertCommand) {
			return sprintf(
				"Insert command for collection '%s' expected to affect 1 row, affected %d.",
				$collection,
				$affectedRows,
			);
		}

		if ($command instanceof UpdateCommand) {
			return sprintf(
				"Update command for collection '%s' with identity %s expected to affect 1 row, affected %d.",
				$collection,
				json_encode($command->getIdentity()),
				$affectedRows,
			);
		}

		return sprintf(
			"Delete command for collection '%s' with identity %s expected to affect 1 row, affected %d.",
			$collection,
			json_encode($command->getIdentity()),
			$affectedRows,
		);
	}
}
