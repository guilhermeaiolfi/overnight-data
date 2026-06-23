<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\Attribute\MapFrom;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Exception\MappingException;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Mapper\Writer\ObjectWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use Tests\ON\Data\Fixture\AbstractUserDto;
use Tests\ON\Data\Fixture\ReadonlyUserDto;
use Tests\ON\Data\Fixture\StatusEnum;
use Tests\ON\Data\Fixture\UserContract;
use Tests\ON\Data\Fixture\UserInputDto;

final class ObjectWriterCachingTest extends TestCase
{
	public function testMultipleMappingsToSameDtoClassProduceDistinctInstances(): void
	{
		$gateway = ConversionGateway::createDefault();

		$first = $gateway->getMapperManager()->map(
			['id' => 1, 'name' => 'Ada', 'age' => 30],
			UserInputDto::class,
			new MappingContext($gateway),
		);
		$second = $gateway->getMapperManager()->map(
			['id' => 2, 'name' => 'Linus', 'age' => 31],
			UserInputDto::class,
			new MappingContext($gateway),
		);

		self::assertNotSame($first, $second);
		self::assertSame(1, $first->id);
		self::assertSame('Ada', $first->name);
		self::assertSame(30, $first->age);
		self::assertSame(2, $second->id);
		self::assertSame('Linus', $second->name);
		self::assertSame(31, $second->age);
	}

	public function testMapFromAliasesContinueToTakePrecedenceOverPropertyNameFallback(): void
	{
		$result = map(['external_name' => 'mapped'])->to(AliasPriorityTarget::class);

		self::assertSame('mapped', $result->preferredName);
		self::assertSame('original', $result->external_name);
	}

	public function testDifferentTargetClassesWithSharedPropertyNamesRemainIsolated(): void
	{
		$gateway = ConversionGateway::createDefault();

		$first = $gateway->getMapperManager()->map(
			['first_name' => 'Ada'],
			FirstNamedTarget::class,
			new MappingContext($gateway),
		);
		$second = $gateway->getMapperManager()->map(
			['display_name' => 'Linus'],
			SecondNamedTarget::class,
			new MappingContext($gateway),
		);

		self::assertSame('Ada', $first->name);
		self::assertSame('Linus', $second->name);
	}

	public function testParentAndSubclassUseSeparateConcreteClassCacheEntries(): void
	{
		$gateway = ConversionGateway::createDefault();

		$parent = $gateway->getMapperManager()->map(
			['parent_name' => 'Ada'],
			ParentCacheTarget::class,
			new MappingContext($gateway),
		);
		$child = $gateway->getMapperManager()->map(
			['parent_name' => 'Linus', 'child_name' => 'Editor'],
			ChildCacheTarget::class,
			new MappingContext($gateway),
		);

		self::assertSame('Ada', $parent->name);
		self::assertSame('Linus', $child->name);
		self::assertSame('Editor', $child->childLabel);
	}

	public function testHeterogeneousTargetsDoNotContaminateEachOtherAcrossCollections(): void
	{
		$gateway = ConversionGateway::createDefault();

		$firstBatch = $gateway->getMapperManager()->map(
			[
				['first_name' => 'Ada'],
				['first_name' => 'Linus'],
			],
			FirstNamedTarget::class,
			(new MappingContext($gateway))->withCollection(true),
		);
		$secondBatch = $gateway->getMapperManager()->map(
			[
				['display_name' => 'Grace'],
				['display_name' => 'Alan'],
			],
			SecondNamedTarget::class,
			(new MappingContext($gateway))->withCollection(true),
		);

		self::assertSame(['Ada', 'Linus'], array_map(static fn (FirstNamedTarget $target): string => $target->name, $firstBatch));
		self::assertSame(['Grace', 'Alan'], array_map(static fn (SecondNamedTarget $target): string => $target->name, $secondBatch));
	}

	public function testStdClassMappingBypassesObjectWriterCaches(): void
	{
		$writer = new ObjectWriter();
		$node = MappingNode::root(['id' => 10], stdClass::class, new MappingContext(ConversionGateway::createDefault()));

		$target = $writer->createTarget($node);
		$written = $writer->write($target, 'id', 10, $node->createChildNode('id', 10));

		self::assertInstanceOf(stdClass::class, $written);
		self::assertSame(10, $written->id);
		self::assertSame([], $this->privatePropertyValue($writer, 'reflections'));
		self::assertSame([], $this->privatePropertyValue($writer, 'propertyMatchers'));
		self::assertSame([], $this->privatePropertyValue($writer, 'validatedTargets'));
	}

	public function testFailedValidationDoesNotBlockLaterValidTargetMapping(): void
	{
		$writer = new ObjectWriter();

		try {
			$writer->createTarget($this->rootNode(UserContract::class));
			self::fail('Expected invalid interface target exception was not thrown.');
		} catch (MappingException $exception) {
			self::assertSame(
				sprintf("Cannot map to interface target '%s'.", UserContract::class),
				$exception->getMessage(),
			);
		}

		$valid = $writer->createTarget($this->rootNode(UserInputDto::class));
		$written = $writer->write($valid, 'id', 10, $this->rootNode(UserInputDto::class)->createChildNode('id', 10));

		self::assertInstanceOf(UserInputDto::class, $written);
		self::assertSame(10, $written->id);
	}

	public function testRepeatedWritesRetainOneMatcherPerConcreteClass(): void
	{
		$writer = new ObjectWriter();
		$parent = new ParentCacheTarget();
		$child = new ChildCacheTarget();
		$node = $this->rootNode(ParentCacheTarget::class);

		$writer->write($parent, 'parent_name', 'Ada', $node->createChildNode('parent_name', 'Ada'));
		$writer->write($parent, 'parent_name', 'Grace', $node->createChildNode('parent_name', 'Grace'));
		$writer->write($child, 'parent_name', 'Linus', $node->createChildNode('parent_name', 'Linus'));
		$writer->write($child, 'child_name', 'Editor', $node->createChildNode('child_name', 'Editor'));

		$matchers = $this->privatePropertyValue($writer, 'propertyMatchers');
		$reflections = $this->privatePropertyValue($writer, 'reflections');

		self::assertCount(2, $matchers);
		self::assertArrayHasKey(ParentCacheTarget::class, $matchers);
		self::assertArrayHasKey(ChildCacheTarget::class, $matchers);
		self::assertCount(2, $reflections);
	}

	#[DataProvider('unsupportedTargetProvider')]
	public function testUnsupportedTargetsStillFailWithExistingMessages(
		string $target,
		string $message,
	): void {
		$writer = new ObjectWriter();

		$this->expectException(MappingException::class);
		$this->expectExceptionMessage($message);

		$writer->createTarget($this->rootNode($target));
	}

	/**
	 * @return iterable<string, array{class-string, string}>
	 */
	public static function unsupportedTargetProvider(): iterable
	{
		yield 'interface' => [
			UserContract::class,
			sprintf("Cannot map to interface target '%s'.", UserContract::class),
		];
		yield 'abstract' => [
			AbstractUserDto::class,
			sprintf("Cannot map to abstract target '%s'.", AbstractUserDto::class),
		];
		yield 'enum' => [
			StatusEnum::class,
			sprintf("Cannot map to enum target '%s'.", StatusEnum::class),
		];
		yield 'representation' => [
			PhpRepresentation::class,
			sprintf("Cannot map to representation target '%s'.", PhpRepresentation::class),
		];
		yield 'readonly class' => [
			ReadonlyClassTarget::class,
			sprintf("Cannot map to readonly target '%s'.", ReadonlyClassTarget::class),
		];
		yield 'readonly property' => [
			ReadonlyUserDto::class,
			sprintf("Cannot map to readonly property '%s::\$id'.", ReadonlyUserDto::class),
		];
	}

	private function rootNode(object|string $target): MappingNode
	{
		return MappingNode::root([], $target, new MappingContext(ConversionGateway::createDefault()));
	}

	private function privatePropertyValue(object $object, string $property): mixed
	{
		$reflection = new ReflectionProperty($object, $property);

		return $reflection->getValue($object);
	}
}

final class AliasPriorityTarget
{
	#[MapFrom('external_name')]
	public string $preferredName = '';

	public string $external_name = 'original';
}

final class FirstNamedTarget
{
	#[MapFrom('first_name')]
	public string $name = '';
}

final class SecondNamedTarget
{
	#[MapFrom('display_name')]
	public string $name = '';
}

class ParentCacheTarget
{
	#[MapFrom('parent_name')]
	public string $name = '';
}

final class ChildCacheTarget extends ParentCacheTarget
{
	#[MapFrom('child_name')]
	public string $childLabel = '';
}

readonly class ReadonlyClassTarget
{
	public int $id;

	public function __construct()
	{
		$this->id = 0;
	}
}
