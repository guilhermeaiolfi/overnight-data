<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\Attribute\MapCollection;
use ON\Data\Mapper\Attribute\MapFrom;
use ON\Data\Mapper\Exception\UnknownMappingKeyException;
use function ON\Data\Mapper\map;
use PHPUnit\Framework\TestCase;
use stdClass;

final class RejectUnknownKeysTest extends TestCase
{
	public function testRejectionIsLocalToTheMappingThatEnablesIt(): void
	{
		$builder = map(['name' => 'Ada', 'nmae' => 'typo']);
		$strict = $builder->rejectUnknownKeys();

		self::assertSame('Ada', $builder->to(UnknownKeyAuthorDto::class)->name);
		self::assertSame('Ada', $strict->rejectUnknownKeys(false)->to(UnknownKeyAuthorDto::class)->name);

		$this->expectException(UnknownMappingKeyException::class);
		$this->expectExceptionMessage("Unknown input key at path 'nmae'");
		$strict->to(UnknownKeyAuthorDto::class);
	}

	public function testMapFromAndConstructorOnlyKeysAreAccepted(): void
	{
		$dto = map(['display_name' => 'Ada', 'externalId' => 42])
			->rejectUnknownKeys()
			->to(UnknownKeyConstructorDto::class);

		self::assertSame('Ada', $dto->name);
		self::assertSame(42, $dto->id);
	}

	public function testUnknownKeyIsRejectedBeforeConstructorHydration(): void
	{
		$this->expectException(UnknownMappingKeyException::class);
		$this->expectExceptionMessage("Unknown input key at path 'externalID'");

		map(['display_name' => 'Ada', 'externalID' => 42])
			->rejectUnknownKeys()
			->to(UnknownKeyConstructorDto::class);
	}

	public function testUnknownNestedKeyReportsTheOriginalInputPath(): void
	{
		try {
			map((object) ['contributors' => [['name' => 'Ada'], ['nmae' => 'Linus']]])
				->rejectUnknownKeys()
				->to(UnknownKeyArticleDto::class);
		} catch (UnknownMappingKeyException $exception) {
			self::assertSame('contributors.1.nmae', $exception->getPath());
			self::assertSame(UnknownKeyAuthorDto::class, $exception->getTargetClass());

			return;
		}

		self::fail('Expected the unknown key in the second contributor to be rejected.');
	}

	public function testFreeFormTargetsStillAcceptArbitraryKeys(): void
	{
		$input = ['source' => ['any' => 'value']];
		$builder = map($input)->rejectUnknownKeys();

		self::assertSame($input, $builder->to([]));
		self::assertSame('value', $builder->to(stdClass::class)->source->any);
	}
}

final class UnknownKeyAuthorDto
{
	public string $name;
}

final readonly class UnknownKeyConstructorDto
{
	public int $id;

	public function __construct(
		#[MapFrom('display_name')]
		public string $name,
		int $externalId,
	) {
		$this->id = $externalId;
	}
}

final class UnknownKeyArticleDto
{
	#[MapFrom('contributors')]
	#[MapCollection(UnknownKeyAuthorDto::class)]
	public array $authors;
}
