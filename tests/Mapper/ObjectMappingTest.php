<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use DateTimeImmutable;
use InvalidArgumentException;
use ON\Data\Mapper\Attribute\MapFrom;
use ON\Data\Mapper\Attribute\MapTo;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\Exception\NoWalkerFoundException;
use ON\Data\Mapper\Exception\NoWriterFoundException;
use ON\Data\Mapper\FieldTypeRegistry;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Mapper\Representation\WireRepresentation;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Fixture\AbstractUserDto;
use Tests\ON\Data\Fixture\AliasSourcePost;
use Tests\ON\Data\Fixture\AliasTargetPost;
use Tests\ON\Data\Fixture\CtorSpyDto;
use Tests\ON\Data\Fixture\MixedValueObject;
use Tests\ON\Data\Fixture\ReadonlyUserDto;
use Tests\ON\Data\Fixture\StatusEnum;
use Tests\ON\Data\Fixture\UserContract;
use Tests\ON\Data\Fixture\UserInputDto;
use Tests\ON\Data\Fixture\UserOutputDto;

final class ObjectMappingTest extends TestCase
{
	protected function setUp(): void
	{
		CtorSpyDto::$constructorCalls = 0;
	}

	public function testArrayMapsToTypedObject(): void
	{
		$result = map([
			'id' => 10,
			'name' => 'Ada',
			'age' => 42,
			'active' => true,
			'user_score' => 3.5,
		])->to(UserInputDto::class);

		self::assertInstanceOf(UserInputDto::class, $result);
		self::assertSame(10, $result->id);
		self::assertSame('Ada', $result->name);
		self::assertSame(42, $result->age);
		self::assertTrue($result->active);
		self::assertSame(3.5, $result->score);
		self::assertNull($result->nickname);
	}

	public function testObjectMapsToArray(): void
	{
		$source = new UserOutputDto();
		$source->id = 10;
		$source->name = 'Ada';
		$source->nickname = null;
		$source->age = 42;
		$source->active = true;
		$source->score = 3.5;
		$source->profile = new MixedValueObject('admin');

		$result = map($source)->to([]);

		self::assertSame(10, $result['id']);
		self::assertSame('Ada', $result['full_name']);
		self::assertNull($result['nickname']);
		self::assertSame(42, $result['age']);
		self::assertTrue($result['active']);
		self::assertSame(3.5, $result['score']);
		self::assertSame($source->profile, $result['profile']);
		self::assertArrayNotHasKey('password', $result);
	}

	public function testObjectMapsToObject(): void
	{
		$source = new UserInputDto();
		$source->id = 10;
		$source->name = 'Ada';
		$source->age = 42;
		$source->score = 9.5;

		$result = map($source)->to(UserInputDto::class);

		self::assertSame(10, $result->id);
		self::assertSame('Ada', $result->name);
		self::assertSame(42, $result->age);
		self::assertSame(9.5, $result->score);
	}

	public function testConstructorIsNotCalled(): void
	{
		$result = map(['id' => 10])->to(CtorSpyDto::class);

		self::assertInstanceOf(CtorSpyDto::class, $result);
		self::assertSame(0, CtorSpyDto::$constructorCalls);
		self::assertSame(10, $result->id);
		self::assertSame('Anonymous', $result->name);
	}

	public function testInheritedAndVisiblePropertyRulesAreApplied(): void
	{
		$result = map([
			'id' => 10,
			'name' => 'Ada',
			'age' => 42,
			'table' => 'admins',
			'privateNote' => 'leak',
			'protectedNote' => 'leak',
		])->to(UserInputDto::class);

		$mapped = map($result)->to([]);

		self::assertSame('users', UserInputDto::$table);
		self::assertSame(10, $mapped['id']);
		self::assertSame('Ada', $mapped['name']);
		self::assertNull($mapped['nickname']);
		self::assertSame(42, $mapped['age']);
	}

	public function testMissingKeysPreserveDefaultsAndUninitializedState(): void
	{
		$result = map(['id' => 10])->to(UserInputDto::class);

		self::assertSame('Anonymous', $result->name);
		self::assertNull($result->nickname);
		self::assertFalse($result->active);
		self::assertSame(0.0, $result->score);
		self::assertFalse(isset($result->age));
	}

	public function testUnknownInputKeysAreIgnoredAndNullableNullIsPreserved(): void
	{
		$result = map(['id' => 10, 'nickname' => null, 'unknown' => 'ignored'])->to(UserInputDto::class);

		self::assertNull($result->nickname);
		self::assertFalse(property_exists($result, 'unknown'));
	}

	public function testInvalidNonNullableAssignmentProducesMappingException(): void
	{
		$this->expectException(MappingException::class);
		$this->expectExceptionMessage(UserInputDto::class . '::$id');
		$this->expectExceptionMessage("path 'id'");

		map(['id' => null])->to(UserInputDto::class);
	}

	public function testMapFromAndMapToAttributesAreUsed(): void
	{
		$inbound = map(['id' => 10, 'age' => 42, 'user_score' => 7.25])->to(UserInputDto::class);
		$outboundSource = new UserOutputDto();
		$outboundSource->id = 10;
		$outboundSource->name = 'Ada';
		$outboundSource->age = 42;
		$outboundSource->profile = new MixedValueObject('admin');

		$outbound = map($outboundSource)->to([]);

		self::assertSame(7.25, $inbound->score);
		self::assertSame('Ada', $outbound['full_name']);
		self::assertArrayNotHasKey('name', $outbound);
	}

	public function testCombinedMapToAndMapFromBehaviorWorksAcrossObjectToObjectMapping(): void
	{
		$source = new AliasSourcePost();
		$source->title = 'Hello';

		$result = map($source)->to(AliasTargetPost::class);

		self::assertSame('Hello', $result->heading);
	}

	public function testHiddenAndUninitializedOutboundPropertiesAreHandled(): void
	{
		$source = new UserOutputDto();
		$source->id = 10;
		$source->name = 'Ada';
		$source->active = true;
		$source->score = 3.5;
		$source->profile = new MixedValueObject('admin');

		$result = map($source)->to([]);

		self::assertArrayNotHasKey('password', $result);
		self::assertArrayNotHasKey('age', $result);
	}

	public function testPrimitiveConversionFromWireRepresentation(): void
	{
		$result = map([
			'id' => '10',
			'name' => 123,
			'age' => '42',
			'active' => 'true',
			'user_score' => '3.5',
		])->from(WireRepresentation::class)->to(UserInputDto::class);

		self::assertSame(10, $result->id);
		self::assertSame('123', $result->name);
		self::assertSame(42, $result->age);
		self::assertTrue($result->active);
		self::assertSame(3.5, $result->score);
	}

	public function testPrimitiveConversionToWireRepresentation(): void
	{
		$source = new UserOutputDto();
		$source->id = 10;
		$source->name = 'Ada';
		$source->nickname = null;
		$source->age = 42;
		$source->active = true;
		$source->score = 3.5;
		$source->profile = new MixedValueObject('admin');

		$result = map($source)->as(WireRepresentation::class)->to([]);

		self::assertSame(10, $result['id']);
		self::assertSame('Ada', $result['full_name']);
		self::assertNull($result['nickname']);
		self::assertSame(42, $result['age']);
		self::assertTrue($result['active']);
		self::assertSame(3.5, $result['score']);
		self::assertSame($source->profile, $result['profile']);
	}

	public function testConversionFailureIncludesCollectionItemPath(): void
	{
		$this->expectException(MappingException::class);
		$this->expectExceptionMessage(UserInputDto::class . '::$id');
		$this->expectExceptionMessage("path '0.id'");

		map([['id' => 'abc', 'age' => 42]])
			->from(WireRepresentation::class)
			->collection()
			->to(UserInputDto::class);
	}

	public function testBuiltInCombinationsWork(): void
	{
		$array = ['id' => 10, 'name' => 'Ada', 'age' => 42];
		$std = map($array)->to(stdClass::class);
		$dto = map($array)->to(UserInputDto::class);
		$stdToStd = map($std)->to(stdClass::class);
		$stdToDto = map($std)->to(UserInputDto::class);
		$dtoToArray = map($dto)->to([]);
		$dtoToStd = map($dto)->to(stdClass::class);
		$dtoToDto = map($dto)->to(UserInputDto::class);

		self::assertSame($array['id'], $std->id);
		self::assertSame($array['id'], $dto->id);
		self::assertSame($array['id'], $stdToStd->id);
		self::assertSame($array['id'], $stdToDto->id);
		self::assertSame($array['id'], $dtoToArray['id']);
		self::assertSame($array['id'], $dtoToStd->id);
		self::assertSame($array['id'], $dtoToDto->id);
	}

	public function testCollectionMappingToAndFromDtos(): void
	{
		$result = map([['id' => 1, 'age' => 30], ['id' => 2, 'age' => 31]])
			->collection()
			->to(UserInputDto::class);

		$first = new UserOutputDto();
		$first->id = 1;
		$first->name = 'Ada';
		$first->age = 30;
		$first->profile = new MixedValueObject('admin');

		$second = new UserOutputDto();
		$second->id = 2;
		$second->name = 'Linus';
		$second->age = 31;
		$second->profile = new MixedValueObject('editor');

		$arrays = map([$first, $second])->collection()->to([]);

		self::assertCount(2, $result);
		self::assertSame(1, $result[0]->id);
		self::assertSame('Ada', $arrays[0]['full_name']);
		self::assertSame(2, $arrays[1]['id']);
	}

	public function testExplicitStdClassMappingsRemainShallow(): void
	{
		$stdClass = map(['id' => 10])->to(stdClass::class);

		self::assertInstanceOf(stdClass::class, $stdClass);
		self::assertSame(['id' => 10], map($stdClass)->to([]));
	}

	public function testUnsupportedTargetsFailClearly(): void
	{
		$gateway = new ConversionGateway(FieldTypeRegistry::createDefault());

		$cases = [
			[AbstractUserDto::class, AbstractUserDto::class],
			[UserContract::class, UserContract::class],
			[StatusEnum::class, StatusEnum::class],
			[ReadonlyUserDto::class, ReadonlyUserDto::class],
			[PhpRepresentation::class, PhpRepresentation::class],
		];

		foreach ($cases as [$target, $message]) {
			try {
				$gateway->getMappers()->map(['id' => 10], $target, new MappingContext($gateway));
				self::fail('Expected mapping exception was not thrown.');
			} catch (MappingException $exception) {
				self::assertStringContainsString($message, $exception->getMessage());
			}
		}
	}

	public function testDateTimeAndBackedEnumsAreExcludedFromObjectWalking(): void
	{
		$this->expectException(NoWalkerFoundException::class);
		map(new DateTimeImmutable('2024-01-01T00:00:00+00:00'))->to([]);
	}

	public function testBackedEnumsAreExcludedFromObjectWalking(): void
	{
		$this->expectException(NoWalkerFoundException::class);
		map(StatusEnum::Active)->to([]);
	}

	public function testNoWriterFoundForUnsupportedScalarTarget(): void
	{
		$this->expectException(NoWriterFoundException::class);
		map(['id' => 10])->to('not-a-class');
	}

	public function testEmptyAttributeNamesAreRejected(): void
	{
		$this->expectException(InvalidArgumentException::class);
		new MapFrom('');
	}

	public function testEmptyMapToNamesAreRejected(): void
	{
		$this->expectException(InvalidArgumentException::class);
		new MapTo('');
	}
}
