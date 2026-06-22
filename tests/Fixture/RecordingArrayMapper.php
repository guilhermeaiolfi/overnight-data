<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\Mapper\MapperInterface;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingRuntime;

final class RecordingArrayMapper implements MapperInterface
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

	public function map(MappingRuntime $runtime): mixed
	{
		$node = $runtime->getMappingNode();
		self::$frames[] = [
			'path' => $node->getPath(),
			'nodeArguments' => $node->getArguments(),
			'contextArguments' => $node->getContext()->getArguments(),
			'nodeCollection' => $node->isCollection(),
			'contextCollection' => $node->getContext()->isCollection(),
		];

		$source = $runtime->getSource();
		if (! is_array($source)) {
			throw new MappingException('RecordingArrayMapper can only map array sources.');
		}

		foreach ($source as $name => $value) {
			$runtime->write(name: $name, value: $value);
		}

		return $runtime->getResult();
	}
}
