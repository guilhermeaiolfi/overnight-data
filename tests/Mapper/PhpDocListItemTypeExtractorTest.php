<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\Metadata\PhpDocListItemTypeExtractor;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\ON\Data\Fixture\AuthorDto;
use Tests\ON\Data\Fixture\BracketedFirst;
use Tests\ON\Data\Fixture\BracketedSecond;

final class PhpDocListItemTypeExtractorTest extends TestCase
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

	public function testNamespaceMethodAndStaticCallPreserveImports(): void
	{
		$property = new ReflectionProperty(SecondNamespace\NamespaceMethodTarget::class, 'items');
		self::assertSame(\DateTimeImmutable::class, $this->resolvePhpDocListTarget($property));
	}

	public function testInterpolationPreservesImportsInBracketedNamespaces(): void
	{
		require_once __DIR__ . '/../Fixture/PhpDocBracketedNamespaces.php';

		$first = new ReflectionProperty(BracketedFirst\ListTarget::class, 'items');
		$second = new ReflectionProperty(BracketedSecond\ListTarget::class, 'items');
		self::assertSame(\DateTimeImmutable::class, $this->resolvePhpDocListTarget($first));
		self::assertSame(AuthorDto::class, $this->resolvePhpDocListTarget($second));
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

	private function resolvePhpDocListTarget(ReflectionProperty $property): ?string
	{
		return (new PhpDocListItemTypeExtractor())->extract($property);
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

final class NamespaceMethod
{
	public static function namespace(): string
	{
		return __NAMESPACE__;
	}
}

NamespaceMethod::namespace();

final class NamespaceMethodTarget
{
	/** @var list<AuthorDto> */
	public array $items = [];
}
