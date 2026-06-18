<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Walker;

use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Support\ArrayPathExpander;

final class ArrayWalker extends Walker
{
	public function __construct(
		private readonly ?ArrayPathExpander $pathExpander = null,
	) {
		parent::__construct();
	}

	public static function canWalk(
		mixed $source,
		MappingContext $context,
	): bool {
		return is_array($source);
	}

	protected function getNodes(
		MappingNode $node,
	): iterable {
		$source = $node->getValue();
		if (! is_array($source)) {
			throw new MappingException('ArrayWalker can only enumerate array sources.');
		}

		$normalized = $this->shouldExpandDottedKeys($node)
			? ($this->pathExpander ?? new ArrayPathExpander())->expand($source)
			: $source;

		foreach ($normalized as $name => $value) {
			yield $node->child($name, $value);
		}
	}

	private function shouldExpandDottedKeys(MappingNode $node): bool
	{
		$options = [];

		foreach ($node->getArguments() as $argument) {
			if ($argument instanceof ArrayWalkerOptions) {
				$options[] = $argument;
			}
		}

		if ($options === []) {
			return true;
		}

		if (count($options) > 1) {
			throw new MappingException('ArrayWalkerOptions is ambiguous: mapping arguments contain multiple direct options.');
		}

		return $options[0]->getExpandDottedKeys();
	}
}
