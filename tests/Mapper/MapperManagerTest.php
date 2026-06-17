<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Exception\DuplicateMapperComponentRegistrationException;
use ON\Data\Mapper\Exception\IncompatibleWalkerException;
use ON\Data\Mapper\Exception\IncompatibleWriterException;
use ON\Data\Mapper\Exception\InvalidMapperComponentException;
use ON\Data\Mapper\Exception\MapperComponentConfigurationException;
use ON\Data\Mapper\Exception\NoWalkerFoundException;
use ON\Data\Mapper\Exception\NoWriterFoundException;
use ON\Data\Mapper\FieldTypeRegistry;
use ON\Data\Mapper\MapperManager;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\Representation\WireRepresentation;
use ON\Data\Mapper\Resolver\ReflectionPropertyFieldResolver;
use ON\Data\Mapper\Walker\ArrayWalker;
use ON\Data\Mapper\Walker\ObjectWalker;
use ON\Data\Mapper\Writer\ArrayWriter;
use ON\Data\Mapper\Writer\ObjectWriter;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Fixture\ComponentTestState;
use Tests\ON\Data\Fixture\CustomMapper;
use Tests\ON\Data\Fixture\MultiRoleComponent;
use Tests\ON\Data\Fixture\NeverWalker;
use Tests\ON\Data\Fixture\NeverWriter;
use Tests\ON\Data\Fixture\SpyArrayWalker;
use Tests\ON\Data\Fixture\SpyArrayWriter;
use Tests\ON\Data\Fixture\SpyResolver;

final class MapperManagerTest extends TestCase
{
	protected function setUp(): void
	{
		ComponentTestState::reset();
	}

	public function testRegistrationClassifiesComponentsWithoutInstantiation(): void
	{
		$manager = new MapperManager($this->gateway());

		$manager->register(SpyArrayWalker::class);
		$manager->register(SpyArrayWriter::class);
		$manager->register(SpyResolver::class);

		self::assertSame([SpyArrayWalker::class], $manager->getRegisteredWalkers());
		self::assertSame([SpyArrayWriter::class], $manager->getRegisteredWriters());
		self::assertSame([SpyResolver::class], $manager->getRegisteredResolvers());
		self::assertSame([], ComponentTestState::$constructed);
	}

	public function testDuplicateRegistrationFails(): void
	{
		$manager = new MapperManager($this->gateway());
		$manager->register(SpyArrayWalker::class);

		$this->expectException(DuplicateMapperComponentRegistrationException::class);
		$manager->register(SpyArrayWalker::class);
	}

	public function testInvalidComponentsFail(): void
	{
		$manager = new MapperManager($this->gateway());

		$this->expectException(InvalidMapperComponentException::class);
		$manager->register(CustomMapper::class);
	}

	public function testMultiRoleComponentsAreRejected(): void
	{
		$manager = new MapperManager($this->gateway());

		$this->expectException(InvalidMapperComponentException::class);
		$manager->register(MultiRoleComponent::class);
	}

	public function testOnlySelectedReusableComponentsAreConstructedAndCached(): void
	{
		$gateway = $this->gateway();
		$manager = new MapperManager($gateway);
		$manager->register(NeverWalker::class);
		$manager->register(SpyArrayWalker::class);
		$manager->register(NeverWriter::class);
		$manager->register(SpyArrayWriter::class);

		$first = $manager->map(['id' => 10], [], new MappingContext($gateway));
		$second = $manager->map(['id' => 11], [], new MappingContext($gateway));

		self::assertSame(['id' => 10], $first);
		self::assertSame(['id' => 11], $second);
		self::assertSame(
			[
				SpyArrayWalker::class => 1,
				SpyArrayWriter::class => 1,
			],
			ComponentTestState::$constructed,
		);
	}

	public function testClearDropsReusableInstances(): void
	{
		$gateway = $this->gateway();
		$manager = new MapperManager($gateway);
		$manager->register(SpyArrayWalker::class);
		$manager->register(SpyArrayWriter::class);

		$manager->map(['id' => 1], [], new MappingContext($gateway));
		$manager->clear();
		$manager->map(['id' => 2], [], new MappingContext($gateway));

		self::assertSame(
			[
				SpyArrayWalker::class => 2,
				SpyArrayWriter::class => 2,
			],
			ComponentTestState::$constructed,
		);
	}

	public function testWarmUpConstructsRegisteredWalkersAndWritersButNotResolvers(): void
	{
		$manager = new MapperManager($this->gateway());
		$manager->register(SpyArrayWalker::class);
		$manager->register(SpyArrayWriter::class);
		$manager->register(SpyResolver::class);

		$manager->warmUp();

		self::assertSame(
			[
				SpyArrayWalker::class => 1,
				SpyArrayWriter::class => 1,
			],
			ComponentTestState::$constructed,
		);
	}

	public function testCustomConstructorWorksAcrossRoles(): void
	{
		$constructed = [];
		$gateway = $this->gateway();
		$manager = new MapperManager(
			$gateway,
			static function (string $component, ConversionGateway $runtime) use (&$constructed): object {
				$constructed[] = [$component, spl_object_id($runtime)];

				return new $component();
			},
		);
		$manager->register(SpyArrayWalker::class);
		$manager->register(SpyArrayWriter::class);
		$manager->register(SpyResolver::class);

		$manager->map(['id' => '10'], [], (new MappingContext($gateway))->withAddedResolverClass(SpyResolver::class));

		self::assertSame(
			[
				[SpyArrayWalker::class, spl_object_id($gateway)],
				[SpyArrayWriter::class, spl_object_id($gateway)],
				[SpyResolver::class, spl_object_id($gateway)],
				[SpyResolver::class, spl_object_id($gateway)],
			],
			$constructed,
		);
	}

	public function testInvalidConstructorReturnIsRejected(): void
	{
		$manager = new MapperManager(
			$this->gateway(),
			static fn (string $component, ConversionGateway $runtime): object => new stdClass(),
		);
		$manager->register(SpyArrayWalker::class);

		$this->expectException(MapperComponentConfigurationException::class);
		$manager->getWalker(SpyArrayWalker::class);
	}

	public function testChangingConstructorAfterReusableInstantiationFails(): void
	{
		$gateway = $this->gateway();
		$manager = new MapperManager($gateway);
		$manager->register(SpyArrayWalker::class);
		$manager->getWalker(SpyArrayWalker::class);

		$this->expectException(MapperComponentConfigurationException::class);
		$manager->setConstructor(static fn (string $component, ConversionGateway $runtime): object => new $component());
	}

	public function testAutomaticSelectionUsesRegistrationOrderWithinEachBucket(): void
	{
		$gateway = $this->gateway();
		$manager = new MapperManager($gateway);
		$manager->register(NeverWalker::class);
		$manager->register(SpyArrayWalker::class);
		$manager->register(NeverWriter::class);
		$manager->register(SpyArrayWriter::class);

		$result = $manager->map(['name' => 'Ada'], [], new MappingContext($gateway));

		self::assertSame(['name' => 'Ada'], $result);
		self::assertSame(1, ComponentTestState::$selectionCalls[NeverWalker::class] ?? 0);
		self::assertSame(1, ComponentTestState::$selectionCalls[SpyArrayWalker::class] ?? 0);
		self::assertSame(1, ComponentTestState::$selectionCalls[NeverWriter::class] ?? 0);
		self::assertSame(1, ComponentTestState::$selectionCalls[SpyArrayWriter::class] ?? 0);
	}

	public function testCreateDefaultRegistersBuiltInsInExpectedOrder(): void
	{
		$manager = MapperManager::createDefault($this->gateway());

		self::assertSame([ArrayWalker::class, ObjectWalker::class], $manager->getRegisteredWalkers());
		self::assertSame([ArrayWriter::class, ObjectWriter::class], $manager->getRegisteredWriters());
		self::assertSame([ReflectionPropertyFieldResolver::class], $manager->getRegisteredResolvers());
	}

	public function testExplicitUnregisteredWalkerWriterAndResolverWork(): void
	{
		$gateway = $this->gateway();
		$manager = new MapperManager($gateway);

		$result = $manager->map(
			['id' => '10'],
			[],
			(new MappingContext($gateway))
				->withWalkerClass(SpyArrayWalker::class)
				->withWriterClass(SpyArrayWriter::class)
				->withAddedResolverClass(SpyResolver::class)
				->withSourceRepresentation(WireRepresentation::class),
		);

		self::assertSame(['id' => 10], $result);
	}

	public function testIncompatibleExplicitWalkerFails(): void
	{
		$manager = new MapperManager($this->gateway());

		$this->expectException(IncompatibleWalkerException::class);
		$manager->map(['id' => 10], [], (new MappingContext($this->gateway()))->withWalkerClass(NeverWalker::class));
	}

	public function testIncompatibleExplicitWriterFails(): void
	{
		$manager = new MapperManager($this->gateway());

		$this->expectException(IncompatibleWriterException::class);
		$manager->map(['id' => 10], [], (new MappingContext($this->gateway()))
			->withWalkerClass(SpyArrayWalker::class)
			->withWriterClass(NeverWriter::class));
	}

	public function testNoWalkerFoundFails(): void
	{
		$manager = new MapperManager($this->gateway());
		$manager->register(SpyArrayWriter::class);

		$this->expectException(NoWalkerFoundException::class);
		$manager->map(new stdClass(), [], new MappingContext($this->gateway()));
	}

	public function testNoWriterFoundFails(): void
	{
		$manager = new MapperManager($this->gateway());
		$manager->register(SpyArrayWalker::class);

		$this->expectException(NoWriterFoundException::class);
		$manager->map(['id' => 10], stdClass::class, new MappingContext($this->gateway()));
	}

	private function gateway(): ConversionGateway
	{
		return new ConversionGateway(FieldTypeRegistry::createDefault());
	}
}
