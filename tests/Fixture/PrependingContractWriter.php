<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingOptions;
use ON\Data\Mapper\Writer\WriterInterface;

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

	public function createTarget(MappingNode $node): ContractDto
	{
		ComponentTestState::recordRuntime(self::class);

		return new ContractDto();
	}

	public function write(
		mixed $target,
		string|int $name,
		mixed $value,
		MappingNode $node,
	): ContractDto {
		$target->{$name} = $value;

		return $target;
	}
}
