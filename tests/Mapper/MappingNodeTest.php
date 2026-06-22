<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\FieldMap;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Fixture\SpyArrayMapper;
use Tests\ON\Data\Fixture\SpyArrayWriter;

final class MappingNodeTest extends TestCase
{
	public function testRootNodeCarriesMappingFrameConfiguration(): void
	{
		$context = (new MappingContext(ConversionGateway::createDefault()))
			->withArguments(['definition'])
			->withFieldMap(FieldMap::fromArray(['id' => 'bigint']))
			->withCollection(true);
		$source = ['author' => ['id' => 2]];
		$node = MappingNode::root($source, [], $context);

		self::assertNull($node->getName());
		self::assertSame($source, $node->getValue());
		self::assertSame([], $node->getTarget());
		self::assertSame($context, $node->getContext());
		self::assertSame(['definition'], $node->getArguments());
		self::assertSame($context->getArguments(), $node->getArguments());
		self::assertSame($context->getFieldMap(), $node->getContext()->getFieldMap());
		self::assertTrue($node->isCollection());
		self::assertSame($context->isCollection(), $node->isCollection());
		self::assertNull($node->getParent());
		self::assertSame('', $node->getPath());
	}

	public function testChildNodeDerivesParentAndDottedPaths(): void
	{
		$context = (new MappingContext(ConversionGateway::createDefault()))
			->withArguments(['users'])
			->withCollection(true);
		$root = MappingNode::root(['author' => ['name' => 'Ada']], [], $context);
		$frame = $root->withTarget([]);
		$author = $frame->createChildNode('author', ['name' => 'Ada']);
		$name = $author->withTarget([])->createChildNode('name', 'Ada');

		self::assertSame($frame, $author->getParent());
		self::assertSame($context, $author->getContext());
		self::assertSame($context->getArguments(), $author->getArguments());
		self::assertSame($context->isCollection(), $author->isCollection());
		self::assertSame('author', $author->getPath());
		self::assertSame('author.name', $name->getPath());
		self::assertSame($root->getValue(), $author->getParentSource());
		self::assertSame([], $author->getParentTarget());
		self::assertSame(['name' => 'Ada'], $name->getParentSource());
	}

	public function testCollectionIndexesAppearInDerivedPaths(): void
	{
		$root = MappingNode::root([['id' => 2]], [], new MappingContext(ConversionGateway::createDefault()));
		$item = $root->createChildNode(0, ['id' => 2]);
		$field = $item->withTarget([])->createChildNode('id', 2);

		self::assertSame('0', $item->getPath());
		self::assertSame('0.id', $field->getPath());
	}

	public function testForMappingDerivesNestedContextAndOverrideBehavior(): void
	{
		$context = (new MappingContext(ConversionGateway::createDefault()))
			->withMapperClass(SpyArrayMapper::class)
			->withWriterClass(SpyArrayWriter::class)
			->withFieldMap(FieldMap::fromArray(['author.id' => 'bigint']));
		$rootValue = ['author' => ['id' => 2]];
		$childValue = ['id' => 2];
		$child = MappingNode::root($rootValue, [], $context->withArguments(['old']))
			->createChildNode('author', $childValue);
		$nestedArguments = ['new'];
		$preservedArguments = ['same'];
		$preservedTarget = [];
		$nested = $child->forMapping(
			target: stdClass::class,
			arguments: $nestedArguments,
			collection: true,
		);
		$preserved = $child->forMapping(
			target: $preservedTarget,
			arguments: $preservedArguments,
			collection: false,
			preserveComponentOverrides: true,
		);

		self::assertSame(stdClass::class, $nested->getTarget());
		self::assertSame($nestedArguments, $nested->getArguments());
		self::assertSame($nestedArguments, $nested->getContext()->getArguments());
		self::assertTrue($nested->isCollection());
		self::assertTrue($nested->getContext()->isCollection());
		self::assertSame($context->getFieldMap(), $nested->getContext()->getFieldMap());
		self::assertNull($nested->getContext()->getMapperClass());
		self::assertNull($nested->getContext()->getWriterClass());
		self::assertSame($preservedArguments, $preserved->getArguments());
		self::assertSame($preservedArguments, $preserved->getContext()->getArguments());
		self::assertFalse($preserved->isCollection());
		self::assertFalse($preserved->getContext()->isCollection());
		self::assertSame($context->getFieldMap(), $preserved->getContext()->getFieldMap());
		self::assertSame(SpyArrayMapper::class, $preserved->getContext()->getMapperClass());
		self::assertSame(SpyArrayWriter::class, $preserved->getContext()->getWriterClass());
	}

	public function testWithContextMethodWasRemoved(): void
	{
		self::assertFalse(method_exists(MappingNode::class, 'withContext'));
	}

	public function testNodeDoesNotExposeSourcePropertyEvidence(): void
	{
		self::assertFalse(method_exists(MappingNode::class, 'getSourceProperty'));
	}

	public function testCycleDetectionUsesOnlyAncestorChain(): void
	{
		$rootValue = new stdClass();
		$root = MappingNode::root($rootValue, [], new MappingContext(ConversionGateway::createDefault()));
		$self = $root->createChildNode('self', $rootValue)->forMapping(
			target: [],
			arguments: [],
		);

		$this->expectException(MappingException::class);
		$this->expectExceptionMessage("path 'self'");
		$self->assertNoObjectCycle();
	}

	public function testSiblingReuseIsNotTreatedAsCycle(): void
	{
		$shared = new stdClass();
		$root = MappingNode::root(['first' => $shared, 'second' => $shared], [], new MappingContext(ConversionGateway::createDefault()));

		$first = $root->createChildNode('first', $shared)->forMapping(
			target: [],
			arguments: [],
		);
		$second = $root->createChildNode('second', $shared)->forMapping(
			target: [],
			arguments: [],
		);

		$first->assertNoObjectCycle();
		$second->assertNoObjectCycle();

		self::assertTrue(true);
	}
}
