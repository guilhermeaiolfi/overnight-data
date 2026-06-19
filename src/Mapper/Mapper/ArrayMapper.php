<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Mapper;

use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\MapperManager;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Support\ArrayPathExpander;

final class ArrayMapper extends Mapper
{
	public function __construct(
		private readonly ?ArrayPathExpander $pathExpander = null,
	) {
	}

	public static function canMap(
		mixed $source,
		MappingContext $context,
	): bool {
		return is_array($source);
	}

	public function map(
		MappingNode $node,
		MapperManager $mapperManager,
	): mixed {
		if ($node->isCollection()) {
			return $this->mapCollection($node, $mapperManager);
		}

		$source = $node->getValue();
		if (! is_array($source)) {
			throw new MappingException('ArrayMapper can only map array sources.');
		}

		$normalized = $this->shouldExpandDottedKeys($node)
			? ($this->pathExpander ?? new ArrayPathExpander())->expand($source)
			: $source;
		$writer = $mapperManager->resolveWriter($node->getTarget(), $node->getContext());
		$result = $writer->prepare($node->getTarget(), $node->getContext());
		$frame = $node->withTarget($result);
		$resolvers = $mapperManager->createResolverChain($frame->getContext());
		$converter = $mapperManager->createFieldConversionCoordinator();

		foreach ($normalized as $name => $value) {
			$child = $frame->child($name, $value);
			$mappedValue = $this->mapChild($child, $resolvers, $converter, $mapperManager);
			$result = $writer->write($result, $child, $mappedValue);
		}

		return $writer->finish($result, $frame->getContext());
	}

	private function shouldExpandDottedKeys(MappingNode $node): bool
	{
		$options = [];

		foreach ($node->getArguments() as $argument) {
			if ($argument instanceof ArrayMapperOptions) {
				$options[] = $argument;
			}
		}

		if ($options === []) {
			return true;
		}

		if (count($options) > 1) {
			throw new MappingException('ArrayMapperOptions is ambiguous: mapping arguments contain multiple direct options.');
		}

		return $options[0]->getExpandDottedKeys();
	}
}
