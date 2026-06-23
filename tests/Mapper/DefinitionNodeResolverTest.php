<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\View\ViewDefinitionInterface;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolver\DefinitionNodeResolver;
use PHPUnit\Framework\TestCase;
use stdClass;

final class DefinitionNodeResolverTest extends TestCase
{
	public function testReturnsNullWhenMappingHasNoArguments(): void
	{
		$resolver = new DefinitionNodeResolver();
		$node = $this->node('id', '42');

		self::assertNull($resolver->resolve($node, $this->runtimeFor($node)));
	}

	public function testReturnsNullWhenArgumentsDoNotContainDefinition(): void
	{
		$resolver = new DefinitionNodeResolver();
		$node = $this->node('id', '42', [new stdClass(), ['id' => '42'], 'users']);

		self::assertNull($resolver->resolve($node, $this->runtimeFor($node)));
	}

	public function testDiscoversOneDirectDefinitionArgumentInAnyPosition(): void
	{
		$registry = new Registry();
		$definition = $registry->collection('users');
		$field = $definition->field('id', 'int')->nullable(true);
		$resolver = new DefinitionNodeResolver();
		$node = $this->node('id', '42', [new stdClass(), $definition]);

		$resolved = $resolver->resolve($node, $this->runtimeFor($node));

		self::assertInstanceOf(LeafNodeResolution::class, $resolved);
		self::assertSame('id', $resolved->getName());
		self::assertSame('int', $resolved->getType());
		self::assertTrue($resolved->isNullable());
		self::assertSame('id', $field->getName());
	}

	public function testResolvesViewDefinitionFieldsToo(): void
	{
		$view = $this->viewDefinition();
		$field = $view->field('title', 'string');
		$resolver = new DefinitionNodeResolver();
		$node = $this->node('title', 123, [$view]);

		$resolved = $resolver->resolve($node, $this->runtimeFor($node));

		self::assertSame('title', $resolved?->getName());
		self::assertSame('string', $resolved?->getType());
		self::assertSame('title', $field->getName());
	}

	public function testMissingFieldReturnsNull(): void
	{
		$definition = $this->collectionDefinition();
		$resolver = new DefinitionNodeResolver();
		$node = $this->node('missing', '42', [$definition]);

		self::assertNull($resolver->resolve($node, $this->runtimeFor($node)));
	}

	public function testIntegerFieldNamesDoNotResolveAgainstDefinitions(): void
	{
		$definition = $this->collectionDefinition();
		$resolver = new DefinitionNodeResolver();
		$node = $this->node(0, '42', [$definition]);

		self::assertNull($resolver->resolve($node, $this->runtimeFor($node)));
	}

	public function testExtraMapperContextIsNotRequiredAndValueTypeDoesNotMatter(): void
	{
		$definition = $this->collectionDefinition();
		$resolver = new DefinitionNodeResolver();
		$node = $this->node('active', ['unexpected' => 'shape'], [$definition]);

		$field = $resolver->resolve($node, $this->runtimeFor($node));

		self::assertSame('bool', $field?->getType());
	}

	public function testMultipleDefinitionsFailClearlyAndIncludeNames(): void
	{
		$registry = new Registry();
		$users = $registry->collection('users');
		$posts = $registry->collection('posts');
		$resolver = new DefinitionNodeResolver();
		$node = $this->node('id', '42', [$users, new stdClass(), $posts]);

		$this->expectException(MappingException::class);
		$this->expectExceptionMessage('ambiguous');
		$this->expectExceptionMessage('"users"');
		$this->expectExceptionMessage('"posts"');

		$resolver->resolve($node, $this->runtimeFor($node));
	}

	public function testAmbiguityDiscoveryIsCachedPerResolverInstance(): void
	{
		$first = $this->createMock(DefinitionInterface::class);
		$first->expects(self::once())->method('getName')->willReturn('users');
		$second = $this->createMock(DefinitionInterface::class);
		$second->expects(self::once())->method('getName')->willReturn('posts');
		$resolver = new DefinitionNodeResolver();
		$node = $this->node('id', '42', [$first, $second]);

		foreach ([1, 2] as $attempt) {
			try {
				$resolver->resolve($node, $this->runtimeFor($node));
			} catch (MappingException $exception) {
				self::assertStringContainsString('ambiguous', $exception->getMessage());
			}
		}
	}

	private function node(string|int $name, mixed $value, array $arguments = []): MappingNode
	{
		return MappingNode::root(
			[],
			[],
			$this->context()->withArguments($arguments),
		)->createChildNode(
			name: $name,
			value: $value,
		);
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

	private function runtimeFor(MappingNode $node): MappingRuntime
	{
		return new MappingRuntime(
			mapperManager: $node->getContext()->getGateway()->getMapperManager(),
			mappingNode: MappingNode::root(
				source: $node->getParentSource(),
				target: $node->getParentTarget(),
				context: $node->getContext(),
			),
		);
	}
}
