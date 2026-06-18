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
use ON\Data\Mapper\Field\BackedEnumFieldType;
use ON\Data\Mapper\Field\BigIntFieldType;
use ON\Data\Mapper\Field\BoolFieldType;
use ON\Data\Mapper\Field\DateFieldType;
use ON\Data\Mapper\Field\DateTimeFieldType;
use ON\Data\Mapper\Field\DateTimeWireCodec;
use ON\Data\Mapper\Field\DecimalFieldType;
use ON\Data\Mapper\Field\FloatFieldType;
use ON\Data\Mapper\Field\IntFieldType;
use ON\Data\Mapper\Field\JsonFieldType;
use ON\Data\Mapper\Field\PassthroughFieldType;
use ON\Data\Mapper\Field\StringFieldType;
use ON\Data\Mapper\Field\UrlFieldType;
use ON\Data\Mapper\MapperManager;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\Representation\WireRepresentation;
use ON\Data\Mapper\Resolver\DefinitionFieldResolver;
use ON\Data\Mapper\Resolver\FieldMapFieldResolver;
use ON\Data\Mapper\Resolver\ReflectionPropertyFieldResolver;
use ON\Data\Mapper\Walker\ArrayWalker;
use ON\Data\Mapper\Walker\ObjectWalker;
use ON\Data\Mapper\Writer\ArrayWriter;
use ON\Data\Mapper\Writer\ObjectWriter;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use Tests\ON\Data\Fixture\ApiRepresentation;
use Tests\ON\Data\Fixture\ComponentTestState;
use Tests\ON\Data\Fixture\ContractDto;
use Tests\ON\Data\Fixture\CustomFieldType;
use Tests\ON\Data\Fixture\CustomMapper;
use Tests\ON\Data\Fixture\InvalidCodecFieldTypeTarget;
use Tests\ON\Data\Fixture\InvalidCodecRepresentationTarget;
use Tests\ON\Data\Fixture\MultiRoleComponent;
use Tests\ON\Data\Fixture\NeverWalker;
use Tests\ON\Data\Fixture\NeverWriter;
use Tests\ON\Data\Fixture\OtherArrayWalker;
use Tests\ON\Data\Fixture\OtherArrayWriter;
use Tests\ON\Data\Fixture\OtherResolver;
use Tests\ON\Data\Fixture\PrependingContractWriter;
use Tests\ON\Data\Fixture\PrependingResolver;
use Tests\ON\Data\Fixture\PrependingStdClassWalker;
use Tests\ON\Data\Fixture\ReplacementTrackingWireCodec;
use Tests\ON\Data\Fixture\SpyArrayWalker;
use Tests\ON\Data\Fixture\SpyArrayWriter;
use Tests\ON\Data\Fixture\SpyResolver;
use Tests\ON\Data\Fixture\TrackingApiCodec;
use Tests\ON\Data\Fixture\TrackingCustomFieldType;
use Tests\ON\Data\Fixture\TrackingWireCodec;
use Tests\ON\Data\Fixture\UserContract;

final class MapperManagerTest extends TestCase
{
	protected function setUp(): void
	{
		ComponentTestState::reset();
		TrackingCustomFieldType::reset();
	}

	public function testRegistrationClassifiesComponentsWithoutInstantiation(): void
	{
		$manager = new MapperManager($this->gateway());

		$manager->register(SpyArrayWalker::class);
		$manager->register(SpyArrayWriter::class);
		$manager->register(SpyResolver::class);
		$manager->register(CustomFieldType::class);
		$manager->register(TrackingWireCodec::class);

		self::assertSame([SpyArrayWalker::class], $manager->getRegisteredWalkers());
		self::assertSame([SpyArrayWriter::class], $manager->getRegisteredWriters());
		self::assertSame([SpyResolver::class], $manager->getRegisteredResolvers());
		self::assertSame(CustomFieldType::class, $manager->getFieldType('custom'));
		self::assertSame(TrackingWireCodec::class, $manager->resolveFieldTypeCodec(TrackingCustomFieldType::class, WireRepresentation::class));
		self::assertSame([], ComponentTestState::$constructed);
	}

	public function testHasRecognizesAllFiveComponentRoles(): void
	{
		$manager = new MapperManager($this->gateway());
		$manager->register(SpyArrayWalker::class);
		$manager->register(SpyArrayWriter::class);
		$manager->register(SpyResolver::class);
		$manager->register(CustomFieldType::class);
		$manager->register(TrackingWireCodec::class);

		self::assertTrue($manager->has(SpyArrayWalker::class));
		self::assertTrue($manager->has(SpyArrayWriter::class));
		self::assertTrue($manager->has(SpyResolver::class));
		self::assertTrue($manager->has(CustomFieldType::class));
		self::assertTrue($manager->has(TrackingWireCodec::class));
	}

	public function testDuplicateRegistrationFails(): void
	{
		$manager = new MapperManager($this->gateway());
		$manager->register(SpyArrayWalker::class);

		$this->expectException(DuplicateMapperComponentRegistrationException::class);
		$manager->register(SpyArrayWalker::class);
	}

	public function testDuplicateCodecRegistrationFails(): void
	{
		$manager = new MapperManager($this->gateway());
		$manager->register(TrackingWireCodec::class);

		$this->expectException(DuplicateMapperComponentRegistrationException::class);
		$manager->register(TrackingWireCodec::class);
	}

	public function testSupersededCodecClassStillCountsAsDuplicateRegistration(): void
	{
		$manager = new MapperManager($this->gateway());
		$manager->register(TrackingWireCodec::class);
		$manager->register(ReplacementTrackingWireCodec::class);

		$this->expectException(DuplicateMapperComponentRegistrationException::class);
		$manager->register(TrackingWireCodec::class);
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

	public function testInvalidCodecMetadataFails(): void
	{
		$manager = new MapperManager($this->gateway());

		try {
			$manager->register(InvalidCodecFieldTypeTarget::class);
			self::fail('Expected invalid codec field type exception was not thrown.');
		} catch (InvalidMapperComponentException $exception) {
			self::assertStringContainsString(InvalidCodecFieldTypeTarget::class, $exception->getMessage());
		}

		try {
			$manager->register(InvalidCodecRepresentationTarget::class);
			self::fail('Expected invalid codec representation exception was not thrown.');
		} catch (InvalidMapperComponentException $exception) {
			self::assertStringContainsString(InvalidCodecRepresentationTarget::class, $exception->getMessage());
		}
	}

	public function testFieldTypesAndCodecsNeverUseConstructorClosure(): void
	{
		$constructed = [];
		$manager = new MapperManager(
			$this->gateway(),
			static function (string $component, ConversionGateway $runtime) use (&$constructed): object {
				$constructed[] = $component;

				return new $component();
			},
		);

		$manager->register(CustomFieldType::class);
		$manager->register(TrackingWireCodec::class);

		self::assertSame([], $constructed);
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

	public function testClearDropsReusableInstancesAndCodecResolutionCache(): void
	{
		$gateway = $this->gateway();
		$manager = new MapperManager($gateway);
		$manager->register(SpyArrayWalker::class);
		$manager->register(SpyArrayWriter::class);
		$manager->register(TrackingWireCodec::class);

		$manager->map(['id' => 1], [], new MappingContext($gateway));
		self::assertSame(TrackingWireCodec::class, $manager->resolveFieldTypeCodec(TrackingCustomFieldType::class, WireRepresentation::class));

		$manager->clear();

		self::assertSame([], $this->resolvedCodecCache($manager));
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

	public function testCustomConstructorWorksAcrossRuntimeRoles(): void
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

	public function testConstructorMustReturnRequestedWalkerClass(): void
	{
		$manager = new MapperManager(
			$this->gateway(),
			static fn (string $component, ConversionGateway $runtime): object => new OtherArrayWalker(),
		);
		$manager->register(SpyArrayWalker::class);

		$this->expectException(MapperComponentConfigurationException::class);
		$manager->getWalker(SpyArrayWalker::class);
	}

	public function testConstructorMustReturnRequestedWriterClass(): void
	{
		$manager = new MapperManager(
			$this->gateway(),
			static fn (string $component, ConversionGateway $runtime): object => new OtherArrayWriter(),
		);
		$manager->register(SpyArrayWriter::class);

		$this->expectException(MapperComponentConfigurationException::class);
		$manager->getWriter(SpyArrayWriter::class);
	}

	public function testConstructorMustReturnRequestedResolverClass(): void
	{
		$manager = new MapperManager(
			$this->gateway(),
			static fn (string $component, ConversionGateway $runtime): object => new OtherResolver(),
		);

		$this->expectException(MapperComponentConfigurationException::class);
		$manager->createResolverChain((new MappingContext($this->gateway()))->withAddedResolverClass(SpyResolver::class));
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

	public function testCreateDefaultRegistersBuiltInsInExpectedOrder(): void
	{
		$manager = MapperManager::createDefault($this->gateway());

		self::assertSame([ArrayWalker::class, ObjectWalker::class], $manager->getRegisteredWalkers());
		self::assertSame([ArrayWriter::class, ObjectWriter::class], $manager->getRegisteredWriters());
		self::assertSame(
			[FieldMapFieldResolver::class, DefinitionFieldResolver::class, ReflectionPropertyFieldResolver::class],
			$manager->getRegisteredResolvers(),
		);
		self::assertSame(
			[
				'string' => StringFieldType::class,
				'text' => PassthroughFieldType::class,
				'bool' => BoolFieldType::class,
				'boolean' => BoolFieldType::class,
				'backed-enum' => BackedEnumFieldType::class,
				'int' => IntFieldType::class,
				'integer' => IntFieldType::class,
				'primary' => IntFieldType::class,
				'smallprimary' => IntFieldType::class,
				'bigint' => BigIntFieldType::class,
				'biginteger' => BigIntFieldType::class,
				'bigprimary' => BigIntFieldType::class,
				'decimal' => DecimalFieldType::class,
				'float' => FloatFieldType::class,
				'double' => FloatFieldType::class,
				'json' => JsonFieldType::class,
				'url' => UrlFieldType::class,
				'date' => DateFieldType::class,
				'datetime' => DateTimeFieldType::class,
				'timestamp' => DateTimeFieldType::class,
			],
			$manager->getRegisteredFieldTypes(),
		);
		self::assertSame(DateTimeWireCodec::class, $manager->resolveFieldTypeCodec(DateTimeFieldType::class, WireRepresentation::class));
	}

	public function testExplicitResolverClassesStillPrecedeDefaultResolvers(): void
	{
		$manager = MapperManager::createDefault($this->gateway());

		self::assertSame(
			[SpyResolver::class, FieldMapFieldResolver::class, DefinitionFieldResolver::class, ReflectionPropertyFieldResolver::class],
			array_map(
				static fn (object $resolver): string => $resolver::class,
				$manager->createResolverChain(
					(new MappingContext($this->gateway()))->withAddedResolverClass(SpyResolver::class),
				),
			),
		);
	}

	public function testPrependedSpecializedWalkerWinsOverObjectWalker(): void
	{
		$gateway = $this->gateway();
		$manager = MapperManager::createDefault($gateway);
		$manager->prepend(PrependingStdClassWalker::class);
		$source = new stdClass();
		$source->id = 10;

		$result = $manager->map($source, [], new MappingContext($gateway));

		self::assertSame(['specialized' => 'walker'], $result);
	}

	public function testPrependedSpecializedWriterWinsOverObjectWriter(): void
	{
		$gateway = $this->gateway();
		$manager = MapperManager::createDefault($gateway);
		$manager->prepend(PrependingContractWriter::class);

		$result = $manager->map(['specialized' => 'writer'], UserContract::class, new MappingContext($gateway));

		self::assertInstanceOf(ContractDto::class, $result);
		self::assertSame('writer', $result->specialized);
	}

	public function testPrependedResolverRunsBeforeDefaultResolver(): void
	{
		$gateway = $this->gateway();
		$manager = MapperManager::createDefault($gateway);
		$manager->prepend(PrependingResolver::class);

		$result = $manager->map(
			['id' => 10],
			[],
			(new MappingContext($gateway))->withOutputRepresentation(WireRepresentation::class),
		);

		self::assertSame(['id' => '10'], $result);
	}

	public function testPrependRejectsFieldTypesAndCodecs(): void
	{
		$manager = new MapperManager($this->gateway());

		try {
			$manager->prepend(CustomFieldType::class);
			self::fail('Expected field type prepend exception was not thrown.');
		} catch (InvalidMapperComponentException $exception) {
			self::assertStringContainsString('cannot be prepended', $exception->getMessage());
		}

		try {
			$manager->prepend(TrackingWireCodec::class);
			self::fail('Expected codec prepend exception was not thrown.');
		} catch (InvalidMapperComponentException $exception) {
			self::assertStringContainsString('cannot be prepended', $exception->getMessage());
		}
	}

	public function testExactCodecIsSelectedAndParentCodecFallsBackForChildren(): void
	{
		$manager = new MapperManager($this->gateway());
		$manager->register(TrackingWireCodec::class);

		self::assertSame(TrackingWireCodec::class, $manager->resolveFieldTypeCodec(TrackingCustomFieldType::class, WireRepresentation::class));
		self::assertSame(TrackingWireCodec::class, $manager->resolveFieldTypeCodec(TrackingCustomFieldType::class, ApiRepresentation::class));
	}

	public function testExactChildCodecOverridesParentCodec(): void
	{
		$manager = new MapperManager($this->gateway());
		$manager->register(TrackingWireCodec::class);
		$manager->register(TrackingApiCodec::class);

		self::assertSame(TrackingApiCodec::class, $manager->resolveFieldTypeCodec(TrackingCustomFieldType::class, ApiRepresentation::class));
	}

	public function testNewExactCodecInvalidatesInheritedCacheAndReplacementWins(): void
	{
		$manager = new MapperManager($this->gateway());
		$manager->register(TrackingWireCodec::class);

		self::assertSame(TrackingWireCodec::class, $manager->resolveFieldTypeCodec(TrackingCustomFieldType::class, ApiRepresentation::class));

		$manager->register(TrackingApiCodec::class);
		self::assertSame(TrackingApiCodec::class, $manager->resolveFieldTypeCodec(TrackingCustomFieldType::class, ApiRepresentation::class));

		$manager->register(ReplacementTrackingWireCodec::class);
		self::assertSame(ReplacementTrackingWireCodec::class, $manager->resolveFieldTypeCodec(TrackingCustomFieldType::class, WireRepresentation::class));
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
		return ConversionGateway::createDefault();
	}

	private function resolvedCodecCache(MapperManager $manager): array
	{
		$reflection = new ReflectionProperty($manager, 'resolvedFieldTypeCodecs');

		return $reflection->getValue($manager);
	}
}
