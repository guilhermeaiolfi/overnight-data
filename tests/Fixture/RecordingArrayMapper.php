<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\Mapper\MapperInterface;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingOptions;

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
		MappingOptions $options,
	): bool {
		self::$selections[] = [
			'arguments' => $options->getArguments(),
			'collection' => $options->isCollection(),
		];

		return is_array($source);
	}

	public function map(MappingContext $context): mixed
	{
		$node = $context->getNode();
		self::$frames[] = [
			'path' => $node->getPath(),
			'nodeArguments' => $node->getArguments(),
			'contextArguments' => $node->getOptions()->getArguments(),
			'nodeCollection' => $node->isCollection(),
			'contextCollection' => $node->getOptions()->isCollection(),
		];

		$source = $context->getSource();
		if (! is_array($source)) {
			throw new MappingException('RecordingArrayMapper can only map array sources.');
		}

		foreach ($source as $name => $value) {
			$context->write(name: $name, value: $value);
		}

		return $context->getResult();
	}
}
