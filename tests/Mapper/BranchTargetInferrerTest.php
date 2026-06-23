<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\Support\BranchTargetInferrer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
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
		$method = new ReflectionMethod(
			BranchTargetInferrer::class,
			'resolvePhpDocListTarget',
		);
		$method->setAccessible(true);

		return $method->invoke(new BranchTargetInferrer(), $property);
	}
}

final class PhpDocBracketListTargetDto
{
	/** @var \Tests\ON\Data\Fixture\AuthorDto[] */
	public array $authors = [];
}

final class PhpDocListGenericTargetDto
{
	/** @var list<\Tests\ON\Data\Fixture\AuthorDto> */
	public array $authors = [];
}

final class PhpDocArrayGenericTargetDto
{
	/** @var array<\Tests\ON\Data\Fixture\AuthorDto> */
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
