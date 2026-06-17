<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

abstract class Mapper implements MapperInterface
{
	public function __construct(
		protected readonly ConversionGateway $gateway,
	) {
	}

	protected function convertInbound(
		mixed $value,
		mixed $fieldSource,
		MappingContext $context,
	): mixed {
		return $this->gateway
			->getFieldConversionCoordinator()
			->convertInbound($value, $fieldSource, $context);
	}

	protected function convertOutbound(
		mixed $value,
		mixed $fieldSource,
		MappingContext $context,
	): mixed {
		return $this->gateway
			->getFieldConversionCoordinator()
			->convertOutbound($value, $fieldSource, $context);
	}

	public static function defaultRepresentations(): array
	{
		return [];
	}
}
