<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use PHPUnit\Framework\TestCase;
use stdClass;

final class MappingNodeTest extends TestCase
{
	public function testGettersAndOpaqueArgumentsArePreserved(): void
	{
		$context = new MappingContext(ConversionGateway::createDefault());
		$arguments = ['property' => 'evidence'];
		$node = new MappingNode('author', ['id' => 2], $context, $arguments);

		self::assertSame('author', $node->getName());
		self::assertSame(['id' => 2], $node->getValue());
		self::assertSame($context, $node->getContext());
		self::assertSame($arguments, $node->getArguments());
		self::assertFalse($node->hasChildMapping());
		self::assertSame($context->getArguments(), $node->getChildArguments());
	}

	public function testWithContextReturnsImmutableClone(): void
	{
		$context = new MappingContext(ConversionGateway::createDefault());
		$next = $context->withPathSegment('author');
		$node = new MappingNode('author', ['id' => 2], $context);
		$updated = $node->withContext($next);

		self::assertNotSame($node, $updated);
		self::assertSame('', $node->getContext()->getPath());
		self::assertSame('author', $updated->getContext()->getPath());
	}

	public function testForChildSupportsSingularAndCollectionInstructions(): void
	{
		$context = new MappingContext(ConversionGateway::createDefault());
		$node = new MappingNode('authors', [['id' => 2]], $context);

		$child = $node->forChild(stdClass::class, true, ['custom']);

		self::assertTrue($child->hasChildMapping());
		self::assertSame(stdClass::class, $child->getChildTarget());
		self::assertTrue($child->isChildCollection());
		self::assertSame(['custom'], $child->getChildArguments());
	}
}
