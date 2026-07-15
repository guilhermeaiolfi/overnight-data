<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Mapper\Representation\StorageRepresentation;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\ORM\Exception\InvalidCommandException;

/**
 * Adapter-agnostic write boundary: projects command values from canonical PHP
 * to storage before delegating to a backend command executor.
 *
 * Does not mutate the original command objects.
 */
final class ConvertingCommandExecutor implements CommandExecutorInterface, TransactionalCommandExecutorInterface
{
	private readonly ConversionGateway $gateway;

	private readonly CommandValueResolver $commandValueResolver;

	public function __construct(
		private readonly CommandExecutorInterface $inner,
		?ConversionGateway $gateway = null,
		?CommandValueResolver $commandValueResolver = null,
	) {
		$this->gateway = $gateway ?? ConversionGateway::createDefault();
		$this->commandValueResolver = $commandValueResolver ?? new CommandValueResolver();
	}

	public function execute(CommandInterface $command): CommandResult
	{
		$this->commandValueResolver->assertReady($command);

		return $this->inner->execute($this->toStorageCommand($command));
	}

	public function transaction(callable $callback): mixed
	{
		if (! $this->inner instanceof TransactionalCommandExecutorInterface) {
			throw new InvalidCommandException(sprintf(
				"Cannot open a persistence transaction because inner executor '%s' does not implement %s.",
				$this->inner::class,
				TransactionalCommandExecutorInterface::class,
			));
		}

		return $this->inner->transaction($callback);
	}

	private function toStorageCommand(CommandInterface $command): CommandInterface
	{
		return match (true) {
			$command instanceof InsertCommand => new InsertCommand(
				$command->getCollection(),
				$this->convertValues($command->getCollection(), $command->getValues()),
				$command->getExpectedAffectedRows(),
			),
			$command instanceof UpdateCommand => new UpdateCommand(
				$command->getCollection(),
				$this->convertValues($command->getCollection(), $command->getIdentity()),
				$this->convertValues($command->getCollection(), $command->getChanges()),
				$command->getExpectedAffectedRows(),
			),
			$command instanceof DeleteCommand => new DeleteCommand(
				$command->getCollection(),
				$this->convertValues($command->getCollection(), $command->getIdentity()),
				$command->getExpectedAffectedRows(),
			),
			default => throw new InvalidCommandException(sprintf(
				"Unsupported persistence command '%s'.",
				$command::class,
			)),
		};
	}

	/**
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	private function convertValues(CollectionInterface $collection, array $values): array
	{
		$converted = [];
		foreach ($values as $fieldName => $value) {
			$field = $this->requireField($collection, (string) $fieldName);
			$converted[$field->getName()] = $this->toStorage($field, $value);
		}

		return $converted;
	}

	private function toStorage(FieldInterface $field, mixed $value): mixed
	{
		return $this->gateway->to(
			PhpRepresentation::class,
			$value,
			StorageRepresentation::class,
			LeafNodeResolution::fromField($field),
		);
	}

	private function requireField(CollectionInterface $collection, string $fieldName): FieldInterface
	{
		$field = $collection->getField($fieldName);
		if ($field === null) {
			throw new InvalidCommandException(sprintf(
				"Persistence command for collection '%s' contains unknown field '%s'.",
				$collection->getName(),
				$fieldName,
			));
		}

		return $field;
	}
}
