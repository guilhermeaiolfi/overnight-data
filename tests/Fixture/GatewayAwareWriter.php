<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use LogicException;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingOptions;
use ON\Data\Mapper\Writer\ObjectWriterState;
use ON\Data\Mapper\Writer\WriterInterface;
use ON\Data\Mapper\Writer\WriterStateInterface;
use stdClass;

final class GatewayAwareWriter implements WriterInterface
{
	public function __construct(
		private readonly ConversionGateway $gateway,
	) {
	}

	public static function canWrite(
		mixed $target,
		MappingOptions $options,
	): bool {
		return $target === stdClass::class || $target instanceof stdClass;
	}

	public function createState(MappingNode $node): WriterStateInterface
	{
		$state = new ObjectWriterState();
		$state->target = new stdClass();

		return $state;
	}

	public function write(
		WriterStateInterface $state,
		string|int $name,
		mixed $value,
		MappingNode $node,
	): void {
		$state instanceof ObjectWriterState || throw new LogicException();
		$state->target instanceof stdClass || throw new LogicException();
		$state->target->gatewayId = spl_object_id($this->gateway);
		$state->target->lastField = (string) $name;
	}

	public function getResult(
		WriterStateInterface $state,
		MappingNode $node,
	): stdClass {
		$state instanceof ObjectWriterState || throw new LogicException();
		$state->target instanceof stdClass || throw new LogicException();

		return $state->target;
	}
}
