<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\View\ViewDefinitionInterface;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\Resolver\DefinitionFieldResolver;
use PHPUnit\Framework\TestCase;
use stdClass;

final class DefinitionFieldResolverTest extends TestCase
{
	public function testReturnsNullWhenMappingHasNoArguments(): void
	{
		$resolver = new DefinitionFieldResolver();

		self::assertNull($resolver->resolve($this->context(), 'id', 'id', '42'));
	}

	public function testReturnsNullWhenArgumentsDoNotContainDefinition(): void
	{
		$resolver = new DefinitionFieldResolver();
		$context = $this->context()->withArguments([new stdClass(), ['id' => '42'], 'users']);

		self::assertNull($resolver->resolve($context, 'id', 'id', '42'));
	}

	public function testDiscoversOneDirectDefinitionArgumentInAnyPosition(): void
	{
		$registry = new Registry();
		$definition = $registry->collection('users');
		$field = $definition->field('id', 'int')->nullable(true);
		$resolver = new DefinitionFieldResolver();

		$context = $this->context()->withArguments([new stdClass(), $definition]);
		$resolved = $resolver->resolve($context, 'id', 'id', '42');

		self::assertInstanceOf(FieldContext::class, $resolved);
		self::assertSame('id', $resolved->getName());
		self::assertSame('int', $resolved->getType());
		self::assertTrue($resolved->isNullable());
		self::assertTrue($resolved->hasField());
		self::assertSame($field, $resolved->getField());
	}

	public function testResolvesViewDefinitionFieldsToo(): void
	{
		$view = $this->viewDefinition();
		$field = $view->field('title', 'string');
		$resolver = new DefinitionFieldResolver();

		$resolved = $resolver->resolve(
			$this->context()->withArguments([$view]),
			'title',
			'title',
			123,
		);

		self::assertSame('title', $resolved?->getName());
		self::assertSame('string', $resolved?->getType());
		self::assertSame($field, $resolved?->getField());
	}

	public function testMissingFieldReturnsNull(): void
	{
		$definition = $this->collectionDefinition();
		$resolver = new DefinitionFieldResolver();

		self::assertNull(
			$resolver->resolve(
				$this->context()->withArguments([$definition]),
				'missing',
				'missing',
				'42',
			),
		);
	}

	public function testIntegerFieldNamesDoNotResolveAgainstDefinitions(): void
	{
		$definition = $this->collectionDefinition();
		$resolver = new DefinitionFieldResolver();

		self::assertNull(
			$resolver->resolve(
				$this->context()->withArguments([$definition]),
				'0',
				0,
				'42',
			),
		);
	}

	public function testExtraWalkerContextIsNotRequiredAndValueTypeDoesNotMatter(): void
	{
		$definition = $this->collectionDefinition();
		$resolver = new DefinitionFieldResolver();

		$field = $resolver->resolve(
			$this->context()->withArguments([$definition]),
			'active',
			'active',
			['unexpected' => 'shape'],
		);

		self::assertSame('bool', $field?->getType());
	}

	public function testMultipleDefinitionsFailClearlyAndIncludeNames(): void
	{
		$registry = new Registry();
		$users = $registry->collection('users');
		$posts = $registry->collection('posts');
		$resolver = new DefinitionFieldResolver();

		$this->expectException(MappingException::class);
		$this->expectExceptionMessage('ambiguous');
		$this->expectExceptionMessage('"users"');
		$this->expectExceptionMessage('"posts"');

		$resolver->resolve(
			$this->context()->withArguments([$users, new stdClass(), $posts]),
			'id',
			'id',
			'42',
		);
	}

	public function testAmbiguityDiscoveryIsCachedPerResolverInstance(): void
	{
		$first = $this->createMock(DefinitionInterface::class);
		$first->expects(self::once())->method('getName')->willReturn('users');
		$second = $this->createMock(DefinitionInterface::class);
		$second->expects(self::once())->method('getName')->willReturn('posts');
		$resolver = new DefinitionFieldResolver();
		$context = $this->context()->withArguments([$first, $second]);

		foreach ([1, 2] as $attempt) {
			try {
				$resolver->resolve($context, 'id', 'id', '42');
			} catch (MappingException $exception) {
				self::assertStringContainsString('ambiguous', $exception->getMessage());
			}
		}
	}

	private function context(): MappingContext
	{
		return new MappingContext($this->gateway());
	}

	private function gateway(): ConversionGateway
	{
		return ConversionGateway::createDefault();
	}

	private function collectionDefinition(): DefinitionInterface
	{
		$registry = new Registry();
		$definition = $registry->collection('users');
		$definition->field('id', 'int');
		$definition->field('active', 'bool');

		return $definition;
	}

	private function viewDefinition(): ViewDefinitionInterface
	{
		$registry = new Registry();

		return $registry->view('user_summary');
	}
}
