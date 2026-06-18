<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Walker\ArrayWalker;
use PHPUnit\Framework\TestCase;

final class ArrayWalkerTest extends TestCase
{
	public function testArraySourcePropertiesAreAlwaysNull(): void
	{
		$nodes = $this->nodesFrom([
			'name' => 'Alice',
		]);

		self::assertCount(1, $nodes);
		self::assertSame('name', $nodes[0]->getName());
		self::assertNull($nodes[0]->getSourceProperty());
	}

	public function testNumericArrayKeyZeroRemainsNodeName(): void
	{
		$nodes = $this->nodesFrom([
			0 => 'Alice',
		]);

		self::assertCount(1, $nodes);
		self::assertSame(0, $nodes[0]->getName());
		self::assertNull($nodes[0]->getSourceProperty());
	}

	/**
	 * @param array<string|int, mixed> $source
	 *
	 * @return list<MappingNode>
	 */
	private function nodesFrom(array $source): array
	{
		$walker = new ArrayWalker();
		$root = MappingNode::root($source, [], new MappingContext(ConversionGateway::createDefault()));
		$method = new \ReflectionMethod(ArrayWalker::class, 'getNodes');
		$method->setAccessible(true);

		return array_values(iterator_to_array($method->invoke($walker, $root), false));
	}
}
