<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use InvalidArgumentException;
use ON\Data\Mapper\Attribute\MapCollection;
use ON\Data\Mapper\Support\DocblockListTargetResolver;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\ON\Data\Fixture\AuthorDto;

final class BranchTargetInferrerTest extends TestCase
{
	public function testResolvesBracketArrayPhpDocListTarget(): void
	{
		self::assertSame(
			AuthorDto::class,
			$this->resolvePhpDocListTarget(
				new ReflectionProperty(PhpDocBracketListTargetDto::class, 'authors'),
			),
		);
	}

	public function testResolvesListGenericPhpDocListTarget(): void
	{
		self::assertSame(
			AuthorDto::class,
			$this->resolvePhpDocListTarget(
				new ReflectionProperty(PhpDocListGenericTargetDto::class, 'authors'),
			),
		);
	}

	public function testResolvesArrayGenericPhpDocListTarget(): void
	{
		self::assertSame(
			AuthorDto::class,
			$this->resolvePhpDocListTarget(
				new ReflectionProperty(PhpDocArrayGenericTargetDto::class, 'authors'),
			),
		);
	}

	public function testImportsUseTheDeclaringNamespaceWhenAFileHasTwoNamespaces(): void
	{
		$property = new ReflectionProperty(SecondNamespace\ListTarget::class, 'items');
		self::assertSame(\DateTimeImmutable::class, $this->resolvePhpDocListTarget($property));
	}

	public function testMissingPhpDocListDeclarationReturnsNull(): void
	{
		self::assertNull($this->resolvePhpDocListTarget(
			new ReflectionProperty(PhpDocMissingTargetDto::class, 'authors'),
		));
	}

	public function testUnresolvablePhpDocListDeclarationReturnsNull(): void
	{
		self::assertNull($this->resolvePhpDocListTarget(
			new ReflectionProperty(PhpDocUnknownTargetDto::class, 'authors'),
		));
	}

	public function testExplicitCollectionItemClassMustExist(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('MapCollection item class "" does not exist.');

		new MapCollection('');
	}

	private function resolvePhpDocListTarget(ReflectionProperty $property): ?string
	{
		return (new DocblockListTargetResolver())->resolve($property);
	}
}

final class PhpDocBracketListTargetDto
{
	/** @var AuthorDto[] */
	public array $authors = [];
}

final class PhpDocListGenericTargetDto
{
	/** @var list<AuthorDto> */
	public array $authors = [];
}

final class PhpDocArrayGenericTargetDto
{
	/** @var array<AuthorDto> */
	public array $authors = [];
}

final class PhpDocMissingTargetDto
{
	public array $authors = [];
}

final class PhpDocUnknownTargetDto
{
	/** @var list<MissingAuthorDto> */
	public array $authors = [];
}

namespace Tests\ON\Data\Mapper\SecondNamespace;

use DateTimeImmutable as AuthorDto;

final class ListTarget
{
	/** @var list<AuthorDto> */
	public array $items = [];
}
