<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\Writer\WriterInterface;
use stdClass;

final class GatewayAwareWriter implements WriterInterface
{
	public function __construct(
		private readonly ConversionGateway $gateway,
	) {
	}

	public static function canWrite(
		mixed $target,
		MappingContext $context,
	): bool {
		return $target === stdClass::class || $target instanceof stdClass;
	}

	public function prepare(
		mixed $target,
		MappingContext $context,
	): stdClass {
		return new stdClass();
	}

	public function write(
		mixed $target,
		string|int $name,
		mixed $value,
		MappingContext $context,
		mixed $walkerArguments = null,
	): stdClass {
		$target->gatewayId = spl_object_id($this->gateway);
		$target->lastField = (string) $name;

		return $target;
	}

	public function finish(
		mixed $target,
		MappingContext $context,
	): stdClass {
		return $target;
	}
}
