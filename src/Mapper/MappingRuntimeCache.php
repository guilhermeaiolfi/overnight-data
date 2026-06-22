<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Mapper\Resolver\NodeResolverInterface;
use ON\Data\Mapper\Writer\WriterInterface;

final class MappingRuntimeCache
{
	private ?WriterInterface $writer = null;

	/**
	 * @var list<NodeResolverInterface>|null
	 */
	private ?array $resolvers = null;

	private ?FieldConversionCoordinator $converter = null;

	public function getWriter(
		MapperManager $mapperManager,
		mixed $target,
		MappingContext $context,
	): WriterInterface {
		return $this->writer ??= $mapperManager->resolveWriter(
			target: $target,
			context: $context,
		);
	}

	/**
	 * @return list<NodeResolverInterface>
	 */
	public function getResolvers(
		MapperManager $mapperManager,
		MappingContext $context,
	): array {
		return $this->resolvers ??= $mapperManager->createResolverChain(
			context: $context,
		);
	}

	public function getConverter(MapperManager $mapperManager): FieldConversionCoordinator
	{
		return $this->converter ??= $mapperManager->createFieldConversionCoordinator();
	}
}
