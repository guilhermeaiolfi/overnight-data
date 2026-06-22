<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Mapper;

use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Support\ArrayPathExpander;

final class ArrayMapper implements MapperInterface
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

	public function map(MappingRuntime $runtime): mixed
	{
		$source = $runtime->getSource();
		if (! is_array($source)) {
			throw new MappingException('ArrayMapper can only map array sources.');
		}

		$source = $this->shouldExpandDottedKeys($runtime->getMappingNode())
			? ($this->pathExpander ?? new ArrayPathExpander())->expand($source)
			: $source;

		foreach ($source as $name => $value) {
			$runtime->write(
				name: $name,
				value: $value,
			);
		}

		return $runtime->getResult();
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
