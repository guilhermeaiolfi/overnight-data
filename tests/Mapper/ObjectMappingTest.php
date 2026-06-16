<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use DateTimeImmutable;
use InvalidArgumentException;
use ON\Data\Mapper\Attribute\MapFrom;
use ON\Data\Mapper\Attribute\MapTo;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\Exception\NoMapperFoundException;
use ON\Data\Mapper\FieldTypeRegistry;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Mapper\Representation\WireRepresentation;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Fixture\AbstractUserDto;
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

		$result = map($source)->toArray();

		self::assertSame(10, $result['id']);
		self::assertSame('Ada', $result['full_name']);
		self::assertNull($result['nickname']);
		self::assertSame(42, $result['age']);
		self::assertTrue($result['active']);
		self::assertSame(3.5, $result['score']);
		self::assertSame($source->profile, $result['profile']);
		self::assertArrayNotHasKey('password', $result);
	}

	public function testConstructorIsNotCalled(): void
	{
		$result = map(['id' => 10])->to(CtorSpyDto::class);

		self::assertInstanceOf(CtorSpyDto::class, $result);
		self::assertSame(0, CtorSpyDto::$constructorCalls);
		self::assertSame(10, $result->id);
		self::assertSame('Anonymous', $result->name);
	}

	public function testInheritedPublicPropertiesAreMapped(): void
	{
		$result = map(['id' => 10, 'age' => 42])->to(UserInputDto::class);

		self::assertSame(10, $result->id);
		self::assertSame(42, $result->age);
	}

	public function testStaticPrivateAndProtectedPropertiesAreIgnored(): void
	{
		$result = map([
			'id' => 10,
			'name' => 'Ada',
			'age' => 42,
			'table' => 'admins',
			'privateNote' => 'leak',
			'protectedNote' => 'leak',
		])->to(UserInputDto::class);

		$mapped = map($result)->toArray();

		self::assertSame('users', UserInputDto::$table);
		self::assertSame(10, $mapped['id']);
		self::assertSame('Ada', $mapped['name']);
		self::assertNull($mapped['nickname']);
		self::assertSame(42, $mapped['age']);
		self::assertFalse($mapped['active']);
		self::assertSame(0.0, $mapped['score']);
	}

	public function testUnknownInputKeysAreIgnored(): void
	{
		$result = map(['id' => 10, 'age' => 42, 'unknown' => 'ignored'])->to(UserInputDto::class);

		self::assertFalse(property_exists($result, 'unknown'));
		self::assertSame(10, $result->id);
	}

	public function testMissingKeysPreserveDefaults(): void
	{
		$result = map(['id' => 10, 'age' => 42])->to(UserInputDto::class);

		self::assertSame('Anonymous', $result->name);
		self::assertNull($result->nickname);
		self::assertFalse($result->active);
		self::assertSame(0.0, $result->score);
	}

	public function testMissingKeysLeaveUninitializedPropertiesUninitialized(): void
	{
		$result = map(['id' => 10])->to(UserInputDto::class);

		self::assertFalse(isset($result->age));
	}

	public function testExplicitNullableNullIsPreserved(): void
	{
		$result = map(['id' => 10, 'age' => 42, 'nickname' => null])->to(UserInputDto::class);

		self::assertNull($result->nickname);
	}

	public function testInvalidNonNullableAssignmentProducesMappingException(): void
	{
		$this->expectException(MappingException::class);
		$this->expectExceptionMessage(UserInputDto::class . '::$id');
		$this->expectExceptionMessage("path 'id'");

		map(['id' => null, 'age' => 42])->to(UserInputDto::class);
	}

	public function testMapFromAttributeIsUsed(): void
	{
		$result = map(['id' => 10, 'age' => 42, 'user_score' => 7.25])->to(UserInputDto::class);

		self::assertSame(7.25, $result->score);
	}

	public function testMapToAttributeIsUsed(): void
	{
		$source = new UserOutputDto();
		$source->id = 10;
		$source->name = 'Ada';
		$source->age = 42;
		$source->profile = new MixedValueObject('admin');

		$result = map($source)->toArray();

		self::assertArrayHasKey('full_name', $result);
		self::assertSame('Ada', $result['full_name']);
		self::assertArrayNotHasKey('name', $result);
	}

	public function testUninitializedOutboundPropertyIsSkipped(): void
	{
		$source = new UserOutputDto();
		$source->id = 10;
		$source->name = 'Ada';
		$source->active = true;
		$source->score = 3.5;

		$result = map($source)->toArray();

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

		$result = map($source)->as(WireRepresentation::class)->toArray();

		self::assertSame(10, $result['id']);
		self::assertSame('Ada', $result['full_name']);
		self::assertNull($result['nickname']);
		self::assertSame(42, $result['age']);
		self::assertTrue($result['active']);
		self::assertSame(3.5, $result['score']);
		self::assertSame($source->profile, $result['profile']);
	}

	public function testConversionFailureIncludesNestedPropertyPath(): void
	{
		$this->expectException(MappingException::class);
		$this->expectExceptionMessage(UserInputDto::class . '::$id');
		$this->expectExceptionMessage("path '0.id'");

		map([['id' => 'abc', 'age' => 42]])
			->from(WireRepresentation::class)
			->collection()
			->to(UserInputDto::class);
	}

	public function testCollectionMappingToDtos(): void
	{
		$result = map([
			['id' => 1, 'age' => 30],
			['id' => 2, 'age' => 31],
		])->collection()->to(UserInputDto::class);

		self::assertCount(2, $result);
		self::assertSame(1, $result[0]->id);
		self::assertSame(31, $result[1]->age);
	}

	public function testCollectionMappingFromDtosToArrays(): void
	{
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

		$result = map([$first, $second])->collection()->toArray();

		self::assertSame('Ada', $result[0]['full_name']);
		self::assertSame(2, $result[1]['id']);
	}

	public function testExistingStdClassMappingsRemainUnchanged(): void
	{
		$stdClass = map(['id' => 10])->to(stdClass::class);

		self::assertInstanceOf(stdClass::class, $stdClass);
		self::assertSame(['id' => 10], map($stdClass)->toArray());
	}

	public function testAbstractTargetFailsClearly(): void
	{
		$this->expectException(MappingException::class);
		$this->expectExceptionMessage(AbstractUserDto::class);

		map(['id' => 10])->to(AbstractUserDto::class);
	}

	public function testInterfaceTargetFailsClearly(): void
	{
		$this->expectException(MappingException::class);
		$this->expectExceptionMessage(UserContract::class);

		map(['id' => 10])->to(UserContract::class);
	}

	public function testEnumTargetFailsClearly(): void
	{
		$this->expectException(MappingException::class);
		$this->expectExceptionMessage(StatusEnum::class);

		map(['id' => 10])->to(StatusEnum::class);
	}

	public function testRepresentationTargetFailsClearly(): void
	{
		$gateway = new ConversionGateway(FieldTypeRegistry::createDefault());

		$this->expectException(MappingException::class);
		$this->expectExceptionMessage(PhpRepresentation::class);

		$gateway
			->getMappers()
			->map(['id' => 10], PhpRepresentation::class, new MappingContext($gateway));
	}

	public function testReadonlyTargetFailsClearly(): void
	{
		$this->expectException(MappingException::class);
		$this->expectExceptionMessage(ReadonlyUserDto::class);

		map(['id' => 10])->to(ReadonlyUserDto::class);
	}

	public function testDateTimeObjectsAreExcludedFromStructuralOutboundMapping(): void
	{
		$this->expectException(NoMapperFoundException::class);

		map(new DateTimeImmutable('2024-01-01T00:00:00+00:00'))->toArray();
	}

	public function testBackedEnumsAreExcludedFromStructuralOutboundMapping(): void
	{
		$this->expectException(NoMapperFoundException::class);

		map(StatusEnum::Active)->toArray();
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
