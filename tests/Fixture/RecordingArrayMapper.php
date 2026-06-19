<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\Mapper\Mapper;
use ON\Data\Mapper\MapperManager;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;

final class RecordingArrayMapper extends Mapper
{
	/**
	 * @var list<array{
	 *     path: string,
	 *     nodeArguments: list<mixed>,
	 *     contextArguments: list<mixed>,
	 *     nodeCollection: bool,
	 *     contextCollection: bool,
	 * }>
	 */
	public static array $frames = [];

	/**
	 * @var list<array{arguments: list<mixed>, collection: bool}>
	 */
	public static array $selections = [];

	public static function reset(): void
	{
		self::$frames = [];
		self::$selections = [];
	}

	public static function canMap(
		mixed $source,
		MappingContext $context,
	): bool {
		self::$selections[] = [
			'arguments' => $context->getArguments(),
			'collection' => $context->isCollection(),
		];

		return is_array($source);
	}

	public function map(
		MappingNode $node,
		MapperManager $mapperManager,
	): mixed {
		self::$frames[] = [
			'path' => $node->getPath(),
			'nodeArguments' => $node->getArguments(),
			'contextArguments' => $node->getContext()->getArguments(),
			'nodeCollection' => $node->isCollection(),
			'contextCollection' => $node->getContext()->isCollection(),
		];

		if ($node->isCollection()) {
			return $this->mapCollection($node, $mapperManager);
		}

		$source = $node->getValue();
		if (! is_array($source)) {
			throw new MappingException('RecordingArrayMapper can only map array sources.');
		}

		$writer = $mapperManager->resolveWriter($node->getTarget(), $node->getContext());
		$result = $writer->prepare($node->getTarget(), $node->getContext());
		$frame = $node->withTarget($result);
		$resolvers = $mapperManager->createResolverChain($frame->getContext());
		$converter = $mapperManager->createFieldConversionCoordinator();

		foreach ($source as $name => $value) {
			$child = $frame->child($name, $value);
			$mappedValue = $this->mapChild($child, $resolvers, $converter, $mapperManager);
			$result = $writer->write($result, $child, $mappedValue);
		}

		return $writer->finish($result, $frame->getContext());
	}
}
