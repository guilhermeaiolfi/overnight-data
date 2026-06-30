<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use LogicException;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingOptions;
use ON\Data\Mapper\Writer\ObjectWriterState;
use ON\Data\Mapper\Writer\WriterInterface;
use ON\Data\Mapper\Writer\WriterStateInterface;

final class PrependingContractWriter implements WriterInterface
{
	public static function canWrite(
		mixed $target,
		MappingOptions $options,
	): bool {
		ComponentTestState::recordSelection(self::class);

		return $target === UserContract::class;
	}

	public function __construct()
	{
		ComponentTestState::recordConstruction(self::class);
	}

	public function createState(MappingNode $node): WriterStateInterface
	{
		ComponentTestState::recordRuntime(self::class);
		$state = new ObjectWriterState();
		$state->target = new ContractDto();

		return $state;
	}

	public function write(
		WriterStateInterface $state,
		string|int $name,
		mixed $value,
		MappingNode $node,
	): void {
		$state instanceof ObjectWriterState || throw new LogicException();
		$state->target instanceof ContractDto || throw new LogicException();
		$state->target->{$name} = $value;
	}

	public function getResult(
		WriterStateInterface $state,
		MappingNode $node,
	): ContractDto {
		$state instanceof ObjectWriterState || throw new LogicException();
		$state->target instanceof ContractDto || throw new LogicException();

		return $state->target;
	}
}
