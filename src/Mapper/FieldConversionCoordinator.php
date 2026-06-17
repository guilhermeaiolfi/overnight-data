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
		MappingNode $node,
	): ?FieldContext {
		foreach ($this->resolvers as $resolver) {
			$field = $resolver->resolve($node);
			if ($field !== null) {
				return $field;
			}
		}

		return null;
	}

	public function convertScalar(
		mixed $value,
		?FieldContext $field,
		MappingNode $node,
	): mixed {
		if ($field === null) {
			return $value;
		}

		$context = $node->getContext();
		$from = $context->getSourceRepresentation();
		$to = $context->getOutputRepresentation();

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
			if ($node->getPath() === '') {
				throw $exception;
			}

			throw new MappingException(
				sprintf("Failed converting value at path '%s'.", $node->getPath()),
				0,
				$exception,
			);
		}
	}
}
