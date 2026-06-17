<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\Representation\PhpRepresentation;

final class FieldConversionCoordinator
{
	/**
	 * @var list<FieldContextResolverInterface>
	 */
	private array $resolvers = [];

	/**
	 * @param list<FieldContextResolverInterface> $resolvers
	 */
	public function __construct(
		private readonly ConversionGateway $gateway,
		array $resolvers = [],
	) {
		foreach ($resolvers as $resolver) {
			$this->addResolver($resolver);
		}
	}

	public function addResolver(
		FieldContextResolverInterface $resolver,
	): self {
		$this->resolvers[] = $resolver;

		return $this;
	}

	public function convertInbound(
		mixed $value,
		mixed $fieldSource,
		MappingContext $context,
	): mixed {
		$representation = $context->getSourceRepresentation();
		if ($representation === null) {
			return $value;
		}

		$field = $this->resolveFieldContext($fieldSource, $context);
		if ($field === null) {
			return $value;
		}

		try {
			return $this->gateway->to(
				$representation,
				$value,
				PhpRepresentation::class,
				$field,
			);
		} catch (MappingException $exception) {
			throw $this->wrapConversionFailure($context, $exception);
		}
	}

	public function convertOutbound(
		mixed $value,
		mixed $fieldSource,
		MappingContext $context,
	): mixed {
		$representation = $context->getOutputRepresentation();
		if ($representation === null) {
			return $value;
		}

		$field = $this->resolveFieldContext($fieldSource, $context);
		if ($field === null) {
			return $value;
		}

		try {
			return $this->gateway->to(
				PhpRepresentation::class,
				$value,
				$representation,
				$field,
			);
		} catch (MappingException $exception) {
			throw $this->wrapConversionFailure($context, $exception);
		}
	}

	private function resolveFieldContext(
		mixed $fieldSource,
		MappingContext $context,
	): ?FieldContext {
		foreach ($this->resolvers as $resolver) {
			$field = $resolver->resolve($fieldSource, $context);
			if ($field !== null) {
				return $field;
			}
		}

		return null;
	}

	private function wrapConversionFailure(
		MappingContext $context,
		MappingException $exception,
	): MappingException {
		if ($context->getPath() === '') {
			return $exception;
		}

		return new MappingException(
			sprintf("Failed converting value at path '%s'.", $context->getPath()),
			0,
			$exception,
		);
	}
}
