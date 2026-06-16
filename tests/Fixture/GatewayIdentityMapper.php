<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Mapper;
use ON\Data\Mapper\MappingContext;
use stdClass;

final class GatewayIdentityMapper extends Mapper
{
	public function __construct(ConversionGateway $gateway)
	{
		parent::__construct($gateway);
		MapperTestState::recordConstruction(self::class);
	}

	public static function canMap(
		mixed $source,
		mixed $target,
		MappingContext $context,
	): bool {
		MapperTestState::recordCanMap(self::class);

		return is_string($source) && ($target === stdClass::class || $target instanceof stdClass);
	}

	public function map(
		mixed $source,
		mixed $target,
		MappingContext $context,
	): stdClass {
		MapperTestState::recordMap(self::class, $context->getArguments(), $context->getPath(), $context->isCollection());

		$result = new stdClass();
		$result->gatewayId = spl_object_id($context->getGateway());
		$result->arguments = $context->getArguments();

		return $result;
	}
}
