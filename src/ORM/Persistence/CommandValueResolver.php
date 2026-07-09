<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\Exception\InvalidCommandException;
use ON\Data\ORM\Record\ValueRef;

final class CommandValueResolver
{
	public function resolve(CommandInterface $command): bool
	{
		$changed = false;

		if ($command instanceof InsertCommand) {
			$values = $command->getValues();
			$changed = $this->resolveValues($values) || $changed;
			if ($changed) {
				$command->setValues($values);
			}

			return $changed;
		}

		if ($command instanceof UpdateCommand) {
			$identity = $command->getIdentity();
			$changes = $command->getChanges();
			$identityChanged = $this->resolveValues($identity);
			$changesChanged = $this->resolveValues($changes);

			if ($identityChanged) {
				$command->setIdentity($identity);
			}

			if ($changesChanged) {
				$command->setChanges($changes);
			}

			return $identityChanged || $changesChanged;
		}

		if ($command instanceof DeleteCommand) {
			$identity = $command->getIdentity();
			$changed = $this->resolveValues($identity);
			if ($changed) {
				$command->setIdentity($identity);
			}

			return $changed;
		}

		return false;
	}

	public function hasUnresolvedValueRefs(CommandInterface $command): bool
	{
		return $this->firstUnresolvedValueRef($command) !== null;
	}

	public function assertReady(CommandInterface $command): void
	{
		$this->resolve($command);
		$unresolved = $this->firstUnresolvedValueRef($command);

		if ($unresolved === null) {
			return;
		}

		[$slot, $field, $ref] = $unresolved;

		throw new InvalidCommandException(sprintf(
			"Cannot execute %s command: %s field '%s' references unresolved value '%s.%s' on record '%s'.",
			$this->commandType($command),
			$slot,
			$field,
			$ref->getRecord()->getCollection()->getName(),
			$ref->getField(),
			$ref->getRecord()->getStateHash(),
		));
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function resolveValues(array &$values): bool
	{
		$changed = false;
		foreach ($values as $field => $value) {
			if (! $value instanceof ValueRef || ! $value->isResolved()) {
				continue;
			}

			$values[$field] = $value->resolve();
			$changed = true;
		}

		return $changed;
	}

	/**
	 * @return array{0: string, 1: string, 2: ValueRef}|null
	 */
	private function firstUnresolvedValueRef(CommandInterface $command): ?array
	{
		foreach ($this->slots($command) as $slot => $values) {
			foreach ($values as $field => $value) {
				if ($value instanceof ValueRef && ! $value->isResolved()) {
					return [$slot, (string) $field, $value];
				}
			}
		}

		return null;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function slots(CommandInterface $command): array
	{
		if ($command instanceof InsertCommand) {
			return ['values' => $command->getValues()];
		}

		if ($command instanceof UpdateCommand) {
			return [
				'identity' => $command->getIdentity(),
				'changes' => $command->getChanges(),
			];
		}

		if ($command instanceof DeleteCommand) {
			return ['identity' => $command->getIdentity()];
		}

		return [];
	}

	private function commandType(CommandInterface $command): string
	{
		return match (true) {
			$command instanceof InsertCommand => 'Insert',
			$command instanceof UpdateCommand => 'Update',
			$command instanceof DeleteCommand => 'Delete',
			default => $command::class,
		};
	}
}
