<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Mapper\Exception\MappingException;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Representation\StorageRepresentation;
use ON\Data\Mapper\Representation\WireRepresentation;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Fixture\MixedValueObject;
use Tests\ON\Data\Fixture\UserInputDto;
use Tests\ON\Data\Fixture\UserOutputDto;

final class DefinitionAwareMappingTest extends TestCase
{
	public function testStorageRepresentationConvertsArrayToPhpArrayUsingDefinition(): void
	{
		$result = map([
			'id' => '42',
			'active' => '0',
			'rating' => '19.5',
		])
			->from(StorageRepresentation::class)
			->args($this->usersDefinition())
			->to([]);

		self::assertSame(
			[
				'id' => 42,
				'active' => false,
				'rating' => 19.5,
			],
			$result,
		);
	}

	public function testWireRepresentationConvertsArrayToPhpArrayUsingDefinition(): void
	{
		$result = map([
			'id' => '42',
			'active' => 'true',
			'rating' => '19.5',
			'name' => 123,
		])
			->from(WireRepresentation::class)
			->args($this->usersDefinition())
			->to([]);

		self::assertSame(42, $result['id']);
		self::assertTrue($result['active']);
		self::assertSame(19.5, $result['rating']);
		self::assertSame('123', $result['name']);
	}

	public function testPhpValuesConvertToStorageUsingDefinition(): void
	{
		$result = map([
			'id' => 42,
			'active' => false,
			'rating' => 19.5,
		])
			->as(StorageRepresentation::class)
			->args($this->usersDefinition())
			->to([]);

		self::assertSame(
			[
				'id' => 42,
				'active' => false,
				'rating' => 19.5,
			],
			$result,
		);
	}

	public function testRepresentationToRepresentationConversionUsesDefinition(): void
	{
		$result = map([
			'id' => '42',
			'active' => 'true',
			'rating' => '19.5',
		])
			->from(WireRepresentation::class)
			->as(StorageRepresentation::class)
			->args($this->usersDefinition())
			->to([]);

		self::assertSame(
			[
				'id' => 42,
				'active' => true,
				'rating' => 19.5,
			],
			$result,
		);
	}

	public function testNoRepresentationBoundaryKeepsRawValues(): void
	{
		$result = map([
			'id' => '42',
			'active' => '0',
		])
			->args($this->usersDefinition())
			->to([]);

		self::assertSame(
			[
				'id' => '42',
				'active' => '0',
			],
			$result,
		);
	}

	public function testUnknownDefinitionFieldRemainsUnchangedWhenNoLaterResolverMatches(): void
	{
		$result = map([
			'id' => '42',
			'unknown' => '007',
		])
			->from(StorageRepresentation::class)
			->args($this->usersDefinition())
			->to([]);

		self::assertSame(42, $result['id']);
		self::assertSame('007', $result['unknown']);
	}

	public function testPartialDefinitionStillAllowsReflectionFallback(): void
	{
		$result = map([
			'id' => '42',
			'age' => '31',
		])
			->from(WireRepresentation::class)
			->args($this->partialDefinition())
			->to(UserInputDto::class);

		self::assertSame(42, $result->id);
		self::assertSame(31, $result->age);
	}

	public function testDefinitionPrecedenceWinsOverReflectionWhenBothCanResolve(): void
	{
		$source = new UserOutputDto();
		$source->id = 42;
		$source->name = 'Ada';
		$source->age = 30;
		$source->active = true;
		$source->score = 19.5;
		$source->profile = new MixedValueObject('admin');

		$result = map($source)
			->from(StorageRepresentation::class)
			->args($this->precedenceDefinition())
			->to([]);

		self::assertSame('42', $result['id']);
	}

	public function testDefinitionAwareConversionSupportsStdClassOutput(): void
	{
		$result = map([
			'id' => '42',
			'active' => '1',
		])
			->from(StorageRepresentation::class)
			->args($this->usersDefinition())
			->to(stdClass::class);

		self::assertSame(42, $result->id);
		self::assertTrue($result->active);
	}

	public function testDefinitionAwareConversionSupportsDtoInputAndOutput(): void
	{
		$source = new UserInputDto();
		$source->id = 42;
		$source->name = 'Ada';
		$source->age = 31;
		$source->active = false;
		$source->score = 19.5;

		$array = map($source)
			->as(StorageRepresentation::class)
			->args($this->dtoDefinition())
			->to([]);

		$dto = map([
			'id' => '42',
			'name' => 'Ada',
			'age' => '31',
			'active' => 'false',
			'user_score' => '19.5',
		])
			->from(WireRepresentation::class)
			->args($this->dtoDefinition())
			->to(UserInputDto::class);

		self::assertSame(42, $array['id']);
		self::assertSame(42, $dto->id);
		self::assertSame(19.5, $dto->score);
	}

	public function testCollectionModeReusesDefinitionForEachItem(): void
	{
		$result = map([
			['id' => '1', 'active' => '1'],
			['id' => '2', 'active' => '0'],
		])
			->from(StorageRepresentation::class)
			->args($this->usersDefinition())
			->collection()
			->to([]);

		self::assertSame(
			[
				['id' => 1, 'active' => true],
				['id' => 2, 'active' => false],
			],
			$result,
		);
	}

	public function testAmbiguousDefinitionsFailBeforeSelectingOneArbitrarily(): void
	{
		$registry = new Registry();
		$users = $registry->collection('users');
		$users->field('id', 'int');
		$posts = $registry->collection('posts');
		$posts->field('id', 'int');

		$this->expectException(MappingException::class);
		$this->expectExceptionMessage('ambiguous');
		$this->expectExceptionMessage('"users"');
		$this->expectExceptionMessage('"posts"');

		map(['id' => '42'])
			->from(StorageRepresentation::class)
			->args($users, $posts)
			->to([]);
	}

	private function usersDefinition(): DefinitionInterface
	{
		$registry = new Registry();
		$definition = $registry->collection('users');
		$definition->field('id', 'int');
		$definition->field('active', 'bool');
		$definition->field('rating', 'float');
		$definition->field('name', 'string');

		return $definition;
	}

	private function partialDefinition(): DefinitionInterface
	{
		$registry = new Registry();
		$definition = $registry->collection('users');
		$definition->field('id', 'int');

		return $definition;
	}

	private function precedenceDefinition(): DefinitionInterface
	{
		$registry = new Registry();
		$definition = $registry->collection('users');
		$definition->field('id', 'string');

		return $definition;
	}

	private function dtoDefinition(): DefinitionInterface
	{
		$registry = new Registry();
		$definition = $registry->collection('users');
		$definition->field('id', 'int');
		$definition->field('name', 'string');
		$definition->field('age', 'int');
		$definition->field('active', 'bool');
		$definition->field('user_score', 'float');

		return $definition;
	}
}
