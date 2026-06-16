<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Mapper;
use ON\Data\Mapper\MappingContext;
use stdClass;

final class SpyArrayToStdClassMapper extends Mapper
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

		return is_array($source) && ($target === stdClass::class || $target instanceof stdClass);
	}

	public function map(
		mixed $source,
		mixed $target,
		MappingContext $context,
	): stdClass {
		MapperTestState::recordMap(self::class, $context->getArguments(), $context->getPath(), $context->isCollection());

		$result = new stdClass();
		foreach ($source as $key => $value) {
			$result->{(string) $key} = $value;
		}

		return $result;
	}
}
