<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use Tests\ON\Data\Fixture\PropertyContextFixture;
use Tests\ON\Data\Fixture\SpyArrayWalker;
use Tests\ON\Data\Fixture\SpyArrayWriter;

final class MappingNodeTest extends TestCase
{
	public function testRootNodeCarriesMappingFrameConfiguration(): void
	{
		$context = new MappingContext(ConversionGateway::createDefault());
		$source = ['author' => ['id' => 2]];
		$node = MappingNode::root($source, [], $context, ['definition'], true);

		self::assertNull($node->getName());
		self::assertSame($source, $node->getValue());
		self::assertSame([], $node->getTarget());
		self::assertSame($context, $node->getContext());
		self::assertSame(['definition'], $node->getArguments());
		self::assertTrue($node->isCollection());
		self::assertNull($node->getParent());
		self::assertSame('', $node->getPath());
	}

	public function testChildNodeDerivesParentAndDottedPaths(): void
	{
		$root = MappingNode::root(['author' => ['name' => 'Ada']], [], new MappingContext(ConversionGateway::createDefault()));
		$frame = $root->withTarget([]);
		$author = $frame->child('author', ['name' => 'Ada']);
		$name = $author->withTarget([])->child('name', 'Ada');

		self::assertSame($frame, $author->getParent());
		self::assertSame('author', $author->getPath());
		self::assertSame('author.name', $name->getPath());
		self::assertSame($root->getValue(), $author->getParentSource());
		self::assertSame([], $author->getParentTarget());
		self::assertSame(['name' => 'Ada'], $name->getParentSource());
	}

	public function testCollectionIndexesAppearInDerivedPaths(): void
	{
		$root = MappingNode::root([['id' => 2]], [], new MappingContext(ConversionGateway::createDefault()));
		$item = $root->child(0, ['id' => 2]);
		$field = $item->withTarget([])->child('id', 2);

		self::assertSame('0', $item->getPath());
		self::assertSame('0.id', $field->getPath());
	}

	public function testForMappingOverridesTargetArgumentsAndCollectionMode(): void
	{
		$context = (new MappingContext(ConversionGateway::createDefault()))
			->withWalkerClass(SpyArrayWalker::class)
			->withWriterClass(SpyArrayWriter::class);
		$rootValue = ['author' => ['id' => 2]];
		$childValue = ['id' => 2];
		$child = MappingNode::root($rootValue, [], $context, ['old'])
			->child('author', $childValue);
		$nestedArguments = ['new'];
		$preservedArguments = ['same'];
		$preservedTarget = [];
		$nested = $child->forMapping(stdClass::class, $nestedArguments, true);
		$preserved = $child->forMapping($preservedTarget, $preservedArguments, false, true);

		self::assertSame(stdClass::class, $nested->getTarget());
		self::assertSame($nestedArguments, $nested->getArguments());
		self::assertTrue($nested->isCollection());
		self::assertNull($nested->getContext()->getWalkerClass());
		self::assertNull($nested->getContext()->getWriterClass());
		self::assertSame(SpyArrayWalker::class, $preserved->getContext()->getWalkerClass());
		self::assertSame(SpyArrayWriter::class, $preserved->getContext()->getWriterClass());
	}

	public function testSourcePropertyEvidenceIsNodeLocal(): void
	{
		$property = new ReflectionProperty(PropertyContextFixture::class, 'name');
		$child = MappingNode::root((object) ['name' => 'Ada'], [], new MappingContext(ConversionGateway::createDefault()))
			->child('name', 'Ada', $property);

		self::assertSame($property, $child->getSourceProperty());
	}

	public function testCycleDetectionUsesOnlyAncestorChain(): void
	{
		$rootValue = new stdClass();
		$root = MappingNode::root($rootValue, [], new MappingContext(ConversionGateway::createDefault()));
		$self = $root->child('self', $rootValue)->forMapping([], []);

		$this->expectException(MappingException::class);
		$this->expectExceptionMessage("path 'self'");
		$self->assertNoObjectCycle();
	}

	public function testSiblingReuseIsNotTreatedAsCycle(): void
	{
		$shared = new stdClass();
		$root = MappingNode::root(['first' => $shared, 'second' => $shared], [], new MappingContext(ConversionGateway::createDefault()));

		$first = $root->child('first', $shared)->forMapping([], []);
		$second = $root->child('second', $shared)->forMapping([], []);

		$first->assertNoObjectCycle();
		$second->assertNoObjectCycle();

		self::assertTrue(true);
	}
}
