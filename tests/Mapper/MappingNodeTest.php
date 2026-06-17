<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use PHPUnit\Framework\TestCase;

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
}
