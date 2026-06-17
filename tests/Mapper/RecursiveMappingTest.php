<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Definition\Registry;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Exception\MappingException;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Representation\StorageRepresentation;
use ON\Data\Mapper\Walker\ArrayWalkerOptions;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Fixture\ArgumentDrivenNodeResolver;
use Tests\ON\Data\Fixture\AuthorDto;
use Tests\ON\Data\Fixture\CustomNodeResolverArgument;
use Tests\ON\Data\Fixture\ParentAwareWriter;
use Tests\ON\Data\Fixture\PostDto;
use Tests\ON\Data\Fixture\SpyArrayWalker;

final class RecursiveMappingTest extends TestCase
{
	protected function setUp(): void
	{
		ParentAwareWriter::reset();
	}

	public function testNestedArrayMapsToNestedDtoAndTypedList(): void
	{
		$result = map([
			'id' => 10,
			'author' => ['id' => 2, 'name' => 'Ada'],
			'authors' => [
				['id' => 3, 'name' => 'Linus'],
				['id' => 4, 'name' => 'Grace'],
			],
		])->to(PostDto::class);

		self::assertSame(10, $result->id);
		self::assertInstanceOf(AuthorDto::class, $result->author);
		self::assertSame('Ada', $result->author->name);
		self::assertCount(2, $result->authors);
		self::assertContainsOnlyInstancesOf(AuthorDto::class, $result->authors);
		self::assertSame('Grace', $result->authors[1]->name);
	}

	public function testDottedKeysExpandByDefaultForArrayDtoAndStdClassTargets(): void
	{
		$row = [
			'id' => '10',
			'author.id' => '2',
			'author.active' => '0',
			'writer.name' => 'Guilherme',
		];

		$arrayResult = map($row)
			->from(StorageRepresentation::class)
			->args($this->postsDefinition())
			->to([]);

		$dtoResult = map($row)
			->from(StorageRepresentation::class)
			->args($this->postsDefinition())
			->to(PostDto::class);

		$stdResult = map($row)
			->from(StorageRepresentation::class)
			->args($this->postsDefinition())
			->to(stdClass::class);

		self::assertSame(['id' => 2, 'active' => false], $arrayResult['author']);
		self::assertSame('Guilherme', $arrayResult['writer']['name']);
		self::assertSame(2, $dtoResult->author->id);
		self::assertFalse($dtoResult->author->active);
		self::assertSame('Guilherme', $dtoResult->writer?->name);
		self::assertSame(2, $stdResult->author->id);
	}

	public function testArrayWalkerOptionsCanDisableExpansion(): void
	{
		$result = map([
			'metadata.version' => '1.0',
		])->args(new ArrayWalkerOptions(false))->to([]);

		self::assertSame(['metadata.version' => '1.0'], $result);
	}

	public function testDottedKeyCollisionsFailClearly(): void
	{
		$this->expectException(MappingException::class);
		$this->expectExceptionMessage('author');

		map([
			'author' => 'Ada',
			'author.id' => 2,
		])->to([]);
	}

	public function testRootWalkerOverrideDoesNotLeakIntoNestedAutomaticSelection(): void
	{
		$result = map(['author' => (object) ['id' => 2]])
			->walker(SpyArrayWalker::class)
			->to([]);

		self::assertSame(['author' => ['id' => 2]], $result);
	}

	public function testCycleProtectionUsesCurrentPath(): void
	{
		$source = new stdClass();
		$source->self = $source;

		$this->expectException(MappingException::class);
		$this->expectExceptionMessage("path 'self'");

		map($source)->to([]);
	}

	public function testCustomNodeResolverSupportsBlueprintLikeExtension(): void
	{
		$result = map([
			'child' => ['id' => 9],
		])
			->nodeResolver(ArgumentDrivenNodeResolver::class)
			->args(new CustomNodeResolverArgument('child', []))
			->to([]);

		self::assertSame(['child' => ['id' => 9]], $result);
	}

	public function testWriterReceivesCompletedNestedValuesAndParentContexts(): void
	{
		$gateway = ConversionGateway::createDefault();
		$gateway->getMappers()->prepend(ParentAwareWriter::class);

		$result = map(
			['author' => ['id' => 2, 'name' => 'Ada']],
			null,
			$gateway,
		)->to([]);

		self::assertSame(['author' => ['id' => 2, 'name' => 'Ada']], $result);
		self::assertSame([
			[
				'path' => 'author.id',
				'hasParentSource' => true,
				'hasParentTarget' => true,
				'valueType' => 'int',
			],
			[
				'path' => 'author.name',
				'hasParentSource' => true,
				'hasParentTarget' => true,
				'valueType' => 'string',
			],
			[
				'path' => 'author',
				'hasParentSource' => false,
				'hasParentTarget' => false,
				'valueType' => 'array',
			],
		], ParentAwareWriter::$writes);
	}

	private function postsDefinition(): object
	{
		$registry = new Registry();
		$users = $registry->collection('users');
		$users->field('id', 'int');
		$users->field('name', 'string');
		$users->field('active', 'bool');

		$posts = $registry->collection('posts');
		$posts->field('id', 'int');
		$posts->belongsTo('author', 'users');
		$posts->belongsTo('writer', 'users');

		return $posts;
	}
}
