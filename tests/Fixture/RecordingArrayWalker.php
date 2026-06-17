<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Walker\Walker;

final class RecordingArrayWalker extends Walker
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

	public static function canWalk(
		mixed $source,
		MappingContext $context,
	): bool {
		self::$selections[] = [
			'arguments' => $context->getArguments(),
			'collection' => $context->isCollection(),
		];

		return is_array($source);
	}

	protected function getNodes(
		MappingNode $node,
	): iterable {
		self::$frames[] = [
			'path' => $node->getPath(),
			'nodeArguments' => $node->getArguments(),
			'contextArguments' => $node->getContext()->getArguments(),
			'nodeCollection' => $node->isCollection(),
			'contextCollection' => $node->getContext()->isCollection(),
		];

		$source = $node->getValue();
		if (! is_array($source)) {
			throw new MappingException('RecordingArrayWalker can only enumerate array sources.');
		}

		foreach ($source as $name => $value) {
			yield $node->child($name, $value);
		}
	}
}
