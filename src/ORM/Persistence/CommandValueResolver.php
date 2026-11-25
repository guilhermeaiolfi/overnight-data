<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\ORM\Exception\InvalidCommandException;
use ON\Data\ORM\Record\ValueRef;

final class CommandValueResolver
{
	/**
	 * Yield field values in order, resolving deferred references before execution.
	 *
	 * @return iterable<string, mixed>
	 */
	public function readyValues(RowCommand $command, string $slot): iterable
	{
		$resolved = null;
		foreach ($command->getValueSlots()[$slot] as $field => $value) {
			if ($value instanceof ValueRef) {
				if ($resolved === null) {
					$this->assertReady($command);
					$resolved = $command->getValueSlots()[$slot];
				}
				$value = $resolved[$field];
			}
			yield $field => $value;
		}
	}

	public static function requireField(CollectionInterface $collection, string $name): FieldInterface
	{
		return $collection->getField($name) ?? throw new InvalidCommandException(sprintf(
			"Persistence command for collection '%s' contains unknown field '%s'.",
			$collection->getName(),
			$name,
		));
	}

	public function resolve(CommandInterface $command): bool
	{
		return $command instanceof RowCommand && $command->resolveValueRefs();
	}

	public function hasUnresolvedValueRefs(CommandInterface $command): bool
	{
		return $this->firstUnresolvedValueRef($command) !== null;
	}

	public function assertReady(CommandInterface $command): void
	{
		if (! $command instanceof RowCommand) {
			return;
		}
		$command->resolveValueRefs();
		$unresolved = $this->firstUnresolvedValueRef($command);

		if ($unresolved === null) {
			return;
		}

		[$slot, $field, $ref] = $unresolved;

		throw new InvalidCommandException(sprintf(
			"Cannot execute %s command: %s field '%s' references unresolved value '%s.%s' on record '%s'.",
			$command->operation,
			$slot,
			$field,
			$ref->getRecord()->getCollection()->getName(),
			$ref->getField(),
			$ref->getRecord()->getStateHash(),
		));
	}

	/**
	 * @return array{0: string, 1: string, 2: ValueRef}|null
	 */
	private function firstUnresolvedValueRef(CommandInterface $command): ?array
	{
		if (! $command instanceof RowCommand) {
			return null;
		}

		foreach ($command->getValueSlots() as $slot => $values) {
			foreach ($values as $field => $value) {
				if ($value instanceof ValueRef && ! $value->isResolved()) {
					return [$slot, (string) $field, $value];
				}
			}
		}

		return null;
	}
}
