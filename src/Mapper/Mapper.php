<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

abstract class Mapper implements MapperInterface
{
	public function __construct(
		protected readonly ConversionGateway $gateway,
	) {
	}

	public static function defaultRepresentations(): array
	{
		return [];
	}
}
