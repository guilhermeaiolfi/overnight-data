<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\Exception\UnexpectedAffectedRowsException;

final class AffectedRowsValidator
{
	public function validate(CommandInterface $command, CommandResult $result): void
	{
		if (! $command instanceof InsertCommand
			&& ! $command instanceof UpdateCommand
			&& ! $command instanceof DeleteCommand
		) {
			return;
		}

		$expected = $command->getExpectedAffectedRows();
		$affectedRows = $result->getAffectedRows();

		if ($expected->accepts($affectedRows)) {
			return;
		}

		throw new UnexpectedAffectedRowsException($this->buildMessage($command, $expected, $affectedRows));
	}

	private function buildMessage(CommandInterface $command, ExpectedAffectedRows $expected, int $affectedRows): string
	{
		$collection = $command->getCollection()->getName();

		if ($command instanceof InsertCommand) {
			return sprintf(
				"Insert command for collection '%s' expected to affect %s, affected %d.",
				$collection,
				$expected->describe(),
				$affectedRows,
			);
		}

		if ($command instanceof UpdateCommand) {
			return sprintf(
				"Update command for collection '%s' with identity %s expected to affect %s, affected %d.",
				$collection,
				json_encode($command->getIdentity()),
				$expected->describe(),
				$affectedRows,
			);
		}

		return sprintf(
			"Delete command for collection '%s' with identity %s expected to affect %s, affected %d.",
			$collection,
			json_encode($command->getIdentity()),
			$expected->describe(),
			$affectedRows,
		);
	}
}
