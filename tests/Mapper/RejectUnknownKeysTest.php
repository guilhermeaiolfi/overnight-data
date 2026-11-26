<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\Attribute\MapCollection;
use ON\Data\Mapper\Attribute\MapFrom;
use ON\Data\Mapper\Exception\UnknownMappingKeyException;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Policy\RejectUnknownKeysPolicy;
use ON\Data\Mapper\Policy\UnknownKeyPolicyInterface;
use PHPUnit\Framework\TestCase;
use stdClass;

final class RejectUnknownKeysTest extends TestCase
{
	public function testRejectionIsLocalToTheMappingThatEnablesIt(): void
	{
		$builder = map(['name' => 'Ada', 'nmae' => 'typo']);
		$strict = $builder->unknownKeyPolicy(RejectUnknownKeysPolicy::class);

		self::assertSame('Ada', $builder->to(UnknownKeyAuthorDto::class)->name);
		self::assertSame('Ada', $strict->unknownKeyPolicy(null)->to(UnknownKeyAuthorDto::class)->name);

		$this->expectException(UnknownMappingKeyException::class);
		$this->expectExceptionMessage("Unknown input key at path 'nmae'");
		$strict->to(UnknownKeyAuthorDto::class);
	}

	public function testMapFromAndConstructorOnlyKeysAreAccepted(): void
	{
		$dto = map(['display_name' => 'Ada', 'externalId' => 42])
			->unknownKeyPolicy(RejectUnknownKeysPolicy::class)
			->to(UnknownKeyConstructorDto::class);

		self::assertSame('Ada', $dto->name);
		self::assertSame(42, $dto->id);
	}

	public function testUnknownKeyIsRejectedBeforeConstructorHydration(): void
	{
		$this->expectException(UnknownMappingKeyException::class);
		$this->expectExceptionMessage("Unknown input key at path 'externalID'");

		map(['display_name' => 'Ada', 'externalID' => 42])
			->unknownKeyPolicy(RejectUnknownKeysPolicy::class)
			->to(UnknownKeyConstructorDto::class);
	}

	public function testUnknownNestedKeyReportsTheOriginalInputPath(): void
	{
		try {
			map((object) ['contributors' => [['name' => 'Ada'], ['nmae' => 'Linus']]])
				->unknownKeyPolicy(RejectUnknownKeysPolicy::class)
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
		$builder = map($input)->unknownKeyPolicy(RejectUnknownKeysPolicy::class);

		self::assertSame($input, $builder->to([]));
		self::assertSame('value', $builder->to(stdClass::class)->source->any);
	}

	public function testCustomPolicyCanCollectEachUnknownKeyOnceAndContinue(): void
	{
		$policy = new class () implements UnknownKeyPolicyInterface {
			/** @var list<array{string, class-string}> */
			public array $keys = [];

			public function handle(string $path, string $targetClass): void
			{
				$this->keys[] = [$path, $targetClass];
			}
		};

		$dto = map(['display_name' => 'Ada', 'externalId' => 42, 'extra' => null, 'another' => false])
			->unknownKeyPolicy($policy)
			->to(UnknownKeyConstructorDto::class);

		self::assertSame('Ada', $dto->name);
		self::assertSame(42, $dto->id);
		self::assertSame([
			['extra', UnknownKeyConstructorDto::class],
			['another', UnknownKeyConstructorDto::class],
		], $policy->keys);
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
