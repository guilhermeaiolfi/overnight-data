<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Mapper\Resolver\FieldResolverInterface;

final class FieldConversionCoordinator
{
	/**
	 * @param list<FieldResolverInterface> $resolvers
	 */
	public function __construct(
		private readonly ConversionGateway $gateway,
		private readonly array $resolvers,
	) {
	}

	public function resolveField(
		MappingContext $mapping,
		string $path,
		string|int $fieldName,
		mixed $value,
		mixed $extra = null,
	): ?FieldContext {
		foreach ($this->resolvers as $resolver) {
			$field = $resolver->resolve($mapping, $path, $fieldName, $value, $extra);
			if ($field !== null) {
				return $field;
			}
		}

		return null;
	}

	public function convertScalar(
		mixed $value,
		?FieldContext $field,
		MappingContext $mapping,
	): mixed {
		if ($field === null) {
			return $value;
		}

		$from = $mapping->getSourceRepresentation();
		$to = $mapping->getOutputRepresentation();

		if ($from === null && $to === null) {
			return $value;
		}

		try {
			return $this->gateway->to(
				$from ?? PhpRepresentation::class,
				$value,
				$to ?? PhpRepresentation::class,
				$field,
			);
		} catch (MappingException $exception) {
			if ($mapping->getPath() === '') {
				throw $exception;
			}

			throw new MappingException(
				sprintf("Failed converting value at path '%s'.", $mapping->getPath()),
				0,
				$exception,
			);
		}
	}
}
