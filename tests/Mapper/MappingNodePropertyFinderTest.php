<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Support\MappingNodePropertyFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use stdClass;
use Tests\ON\Data\Fixture\PropertyContextFixture;
use Tests\ON\Data\Fixture\UserInputDto;

final class MappingNodePropertyFinderTest extends TestCase
{
	public function testFindSourcePropertyDelegatesToNodeEvidence(): void
	{
		$property = new ReflectionProperty(PropertyContextFixture::class, 'name');
		$node = MappingNode::root(
			(new ReflectionClass(PropertyContextFixture::class))->newInstanceWithoutConstructor(),
			[],
			$this->context(),
		)->createChildNode('name', 'Ada');

		$found = $this->finder()->findSourceProperty($node);

		self::assertSame($property->getDeclaringClass()->getName(), $found?->getDeclaringClass()->getName());
		self::assertSame($property->getName(), $found?->getName());
	}

	public function testTargetPropertyLookupSupportsMapFrom(): void
	{
		$target = (new ReflectionClass(UserInputDto::class))->newInstanceWithoutConstructor();
		$node = MappingNode::root(
			(new ReflectionClass(PropertyContextFixture::class))->newInstanceWithoutConstructor(),
			$target,
			$this->context(),
		)
			->withTarget($target)
			->createChildNode('user_score', '3.5');

		$property = $this->finder()->findTargetProperty($node);

		self::assertInstanceOf(ReflectionProperty::class, $property);
		self::assertSame('score', $property->getName());
	}

	#[DataProvider('nonReflectableTargetProvider')]
	public function testNonReflectableTargetsReturnNoTargetProperty(mixed $preparedTarget): void
	{
		$node = MappingNode::root([], $preparedTarget, $this->context())
			->withTarget($preparedTarget)
			->createChildNode('name', 'Ada');

		self::assertNull($this->finder()->findTargetProperty($node));
	}

	public static function nonReflectableTargetProvider(): array
	{
		return [
			'stdClass' => [new stdClass()],
			'array' => [[]],
			'string' => ['target'],
			'int' => [123],
			'bool' => [true],
			'null' => [null],
		];
	}

	private function finder(): MappingNodePropertyFinder
	{
		return new MappingNodePropertyFinder();
	}

	private function context(): MappingContext
	{
		return new MappingContext(ConversionGateway::createDefault());
	}
}
