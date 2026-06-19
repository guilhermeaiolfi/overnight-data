<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;

final class FieldConversionCoordinator
{
	public function __construct(
		private readonly ConversionGateway $gateway,
	) {
	}

	public function convert(
		mixed $value,
		LeafNodeResolutionInterface $leaf,
		MappingNode $node,
	): mixed {
		if ($leaf->getType() === null) {
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
				$leaf,
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
