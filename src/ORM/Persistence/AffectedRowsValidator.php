<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\Exception\UnexpectedAffectedRowsException;

final class AffectedRowsValidator
{
	public function validate(CommandInterface $command, CommandResult $result): void
	{
		if (! $command instanceof RowCommand) {
			return;
		}

		$expected = $command->getExpectedAffectedRows();
		$affectedRows = $result->getAffectedRows();

		if ($expected->accepts($affectedRows)) {
			return;
		}

		$identity = $command->getValueSlots()['identity'] ?? null;

		throw new UnexpectedAffectedRowsException(sprintf(
			"%s command for collection '%s'%s expected to affect %s, affected %d.",
			$command->operation,
			$command->getCollection()->getName(),
			$identity === null ? '' : ' with identity ' . json_encode($identity),
			$expected->describe(),
			$affectedRows,
		));
	}
}
