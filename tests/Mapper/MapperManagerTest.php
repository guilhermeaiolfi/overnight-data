<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\ArrayToObjectMapper;
use ON\Data\Mapper\ArrayToStdClassMapper;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Exception\DuplicateMapperRegistrationException;
use ON\Data\Mapper\Exception\IncompatibleMapperException;
use ON\Data\Mapper\Exception\InvalidMapperClassException;
use ON\Data\Mapper\Exception\MapperConfigurationException;
use ON\Data\Mapper\Exception\MapperNotFoundException;
use ON\Data\Mapper\Exception\NoMapperFoundException;
use ON\Data\Mapper\FieldTypeRegistry;
use ON\Data\Mapper\MapperInterface;
use ON\Data\Mapper\MapperManager;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\ObjectToArrayMapper;
use ON\Data\Mapper\StdClassToArrayMapper;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Fixture\CustomMapper;
use Tests\ON\Data\Fixture\MapperTestState;
use Tests\ON\Data\Fixture\NeverMapper;
use Tests\ON\Data\Fixture\SpyArrayToStdClassMapper;
use Tests\ON\Data\Fixture\SpyStdClassToArrayMapper;

final class MapperManagerTest extends TestCase
{
	protected function setUp(): void
	{
		MapperTestState::reset();
	}

	public function testRegistrationDoesNotInstantiateAMapper(): void
	{
		$manager = new MapperManager($this->gateway());

		$manager->register(SpyArrayToStdClassMapper::class);

		self::assertSame([], MapperTestState::$constructed);
	}

	public function testOnlyTheSelectedMapperIsInstantiated(): void
	{
		$manager = new MapperManager($this->gateway());
		$manager->register(NeverMapper::class);
		$manager->register(SpyArrayToStdClassMapper::class);

		$result = $manager->map(['id' => 10], stdClass::class, new MappingContext($this->gateway()));

		self::assertSame(10, $result->id);
		self::assertSame([SpyArrayToStdClassMapper::class => 1], MapperTestState::$constructed);
	}

	public function testSelectedMapperInstanceIsReused(): void
	{
		$gateway = $this->gateway();
		$manager = new MapperManager($gateway);
		$manager->register(SpyArrayToStdClassMapper::class);

		$first = $manager->getMapper(SpyArrayToStdClassMapper::class);
		$second = $manager->getMapper(SpyArrayToStdClassMapper::class);

		self::assertSame($first, $second);
		self::assertSame([SpyArrayToStdClassMapper::class => 1], MapperTestState::$constructed);
	}

	public function testClearCausesANewInstanceOnTheNextMapping(): void
	{
		$gateway = $this->gateway();
		$manager = new MapperManager($gateway);
		$manager->register(SpyArrayToStdClassMapper::class);

		$manager->map(['id' => 1], stdClass::class, new MappingContext($gateway));
		$manager->clear();
		$manager->map(['id' => 2], stdClass::class, new MappingContext($gateway));

		self::assertSame([SpyArrayToStdClassMapper::class => 2], MapperTestState::$constructed);
	}

	public function testWarmUpConstructsAllRegisteredMappers(): void
	{
		$manager = new MapperManager($this->gateway());
		$manager->register(SpyArrayToStdClassMapper::class);
		$manager->register(SpyStdClassToArrayMapper::class);

		$manager->warmUp();

		self::assertSame(
			[
				SpyArrayToStdClassMapper::class => 1,
				SpyStdClassToArrayMapper::class => 1,
			],
			MapperTestState::$constructed,
		);
	}

	public function testDuplicateRegistrationFails(): void
	{
		$manager = new MapperManager($this->gateway());
		$manager->register(SpyArrayToStdClassMapper::class);

		$this->expectException(DuplicateMapperRegistrationException::class);
		$manager->register(SpyArrayToStdClassMapper::class);
	}

	public function testInvalidMapperClassesFail(): void
	{
		$manager = new MapperManager($this->gateway());

		$this->expectException(InvalidMapperClassException::class);
		$manager->register(CustomMapper::class);
	}

	public function testCustomConstructorClosureWorks(): void
	{
		$constructed = [];
		$gateway = $this->gateway();
		$manager = new MapperManager(
			$gateway,
			static function (string $mapper, ConversionGateway $runtime) use (&$constructed): MapperInterface {
				$constructed[] = [$mapper, spl_object_id($runtime)];

				return new $mapper($runtime);
			},
		);
		$manager->register(SpyArrayToStdClassMapper::class);

		$manager->map(['id' => 10], stdClass::class, new MappingContext($gateway));

		self::assertSame([[SpyArrayToStdClassMapper::class, spl_object_id($gateway)]], $constructed);
	}

	public function testChangingTheConstructorAfterInstantiationFails(): void
	{
		$gateway = $this->gateway();
		$manager = new MapperManager($gateway);
		$manager->register(SpyArrayToStdClassMapper::class);
		$manager->getMapper(SpyArrayToStdClassMapper::class);

		$this->expectException(MapperConfigurationException::class);
		$manager->setConstructor(static fn (string $mapper, ConversionGateway $runtime): MapperInterface => new $mapper($runtime));
	}

	public function testAutomaticCanMapSelection(): void
	{
		$gateway = $this->gateway();
		$manager = new MapperManager($gateway);
		$manager->register(NeverMapper::class);
		$manager->register(SpyArrayToStdClassMapper::class);

		$result = $manager->map(['name' => 'Ada'], stdClass::class, new MappingContext($gateway));

		self::assertSame('Ada', $result->name);
		self::assertSame(1, MapperTestState::$canMapCalls[NeverMapper::class] ?? 0);
		self::assertSame(1, MapperTestState::$canMapCalls[SpyArrayToStdClassMapper::class] ?? 0);
	}

	public function testCreateDefaultRegistersMappersInSpecificityOrder(): void
	{
		$manager = MapperManager::createDefault($this->gateway());

		self::assertSame(
			[
				ArrayToStdClassMapper::class,
				StdClassToArrayMapper::class,
				ArrayToObjectMapper::class,
				ObjectToArrayMapper::class,
			],
			$manager->getRegisteredMappers(),
		);
	}

	public function testExplicitUsingSelection(): void
	{
		$gateway = $this->gateway();
		$manager = new MapperManager($gateway);
		$manager->register(NeverMapper::class);
		$manager->register(SpyArrayToStdClassMapper::class);

		$result = $manager->map(
			['name' => 'Ada'],
			stdClass::class,
			(new MappingContext($gateway))->withMapperClass(SpyArrayToStdClassMapper::class),
		);

		self::assertSame('Ada', $result->name);
		self::assertSame(0, MapperTestState::$canMapCalls[NeverMapper::class] ?? 0);
		self::assertSame(1, MapperTestState::$canMapCalls[SpyArrayToStdClassMapper::class] ?? 0);
	}

	public function testExplicitUsingRejectsAnIncompatibleMapper(): void
	{
		$gateway = $this->gateway();
		$manager = new MapperManager($gateway);
		$manager->register(NeverMapper::class);

		$this->expectException(IncompatibleMapperException::class);
		$manager->map(
			['name' => 'Ada'],
			stdClass::class,
			(new MappingContext($gateway))->withMapperClass(NeverMapper::class),
		);
	}

	public function testNoMapperFoundError(): void
	{
		$manager = new MapperManager($this->gateway());

		$this->expectException(NoMapperFoundException::class);
		$manager->map(['name' => 'Ada'], stdClass::class, new MappingContext($this->gateway()));
	}

	public function testUnknownExplicitMapperFails(): void
	{
		$gateway = $this->gateway();
		$manager = new MapperManager($gateway);

		$this->expectException(MapperNotFoundException::class);
		$manager->map(
			['name' => 'Ada'],
			stdClass::class,
			(new MappingContext($gateway))->withMapperClass(SpyArrayToStdClassMapper::class),
		);
	}

	private function gateway(): ConversionGateway
	{
		return new ConversionGateway(FieldTypeRegistry::createDefault());
	}
}
