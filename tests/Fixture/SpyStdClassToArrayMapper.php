<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Mapper;
use ON\Data\Mapper\MappingContext;
use stdClass;

final class SpyStdClassToArrayMapper extends Mapper
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

		return $source instanceof stdClass && is_array($target);
	}

	public function map(
		mixed $source,
		mixed $target,
		MappingContext $context,
	): array {
		MapperTestState::recordMap(self::class, $context->getArguments(), $context->getPath(), $context->isCollection());

		return get_object_vars($source);
	}
}
