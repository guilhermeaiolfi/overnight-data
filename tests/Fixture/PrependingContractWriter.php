<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Writer\WriterInterface;

final class PrependingContractWriter implements WriterInterface
{
	public static function canWrite(
		mixed $target,
		MappingContext $context,
	): bool {
		ComponentTestState::recordSelection(self::class);

		return $target === UserContract::class;
	}

	public function __construct()
	{
		ComponentTestState::recordConstruction(self::class);
	}

	public function prepare(
		mixed $target,
		MappingContext $context,
	): ContractDto {
		ComponentTestState::recordRuntime(self::class, $context->getPath());

		return new ContractDto();
	}

	public function write(
		mixed $target,
		MappingNode $node,
		mixed $value,
	): ContractDto {
		$target->{$node->getName()} = $value;

		return $target;
	}

	public function finish(
		mixed $target,
		MappingContext $context,
	): ContractDto {
		return $target;
	}
}
