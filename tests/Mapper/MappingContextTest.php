<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\FieldMap;
use ON\Data\Mapper\MappingOptions;
use ON\Data\Mapper\Representation\WireRepresentation;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\Fixture\SpyArrayMapper;
use Tests\ON\Data\Fixture\SpyArrayWriter;
use Tests\ON\Data\Fixture\SpyResolver;

final class MappingContextTest extends TestCase
{
	public function testMappingContextStoresOnlyMappingWideConfiguration(): void
	{
		$gateway = ConversionGateway::createDefault();
		$context = (new MappingOptions($gateway))
			->withSourceRepresentation(WireRepresentation::class)
			->withOutputRepresentation(WireRepresentation::class)
			->withMapperClass(SpyArrayMapper::class)
			->withWriterClass(SpyArrayWriter::class)
			->withResolverClasses([SpyResolver::class])
			->withArguments(['users'])
			->withFieldMap(FieldMap::fromArray(['id' => 'bigint']))
			->withCollection(true);

		self::assertSame($gateway, $context->getGateway());
		self::assertSame(WireRepresentation::class, $context->getSourceRepresentation());
		self::assertSame(WireRepresentation::class, $context->getOutputRepresentation());
		self::assertSame(SpyArrayMapper::class, $context->getMapperClass());
		self::assertSame(SpyArrayWriter::class, $context->getWriterClass());
		self::assertSame([SpyResolver::class], $context->getResolverClasses());
		self::assertSame(['users'], $context->getArguments());
		self::assertSame(['id' => ['type' => 'bigint', 'nullable' => false]], $context->getFieldMap()?->getFields());
		self::assertTrue($context->isCollection());
	}

	public function testWithMethodsRemainImmutable(): void
	{
		$context = new MappingOptions(ConversionGateway::createDefault());
		$fieldMap = FieldMap::fromArray(['id' => 'bigint']);
		$updated = $context
			->withMapperClass(SpyArrayMapper::class)
			->withAddedResolverClass(SpyResolver::class)
			->withFieldMap($fieldMap)
			->asCollection();

		self::assertNull($context->getMapperClass());
		self::assertSame([], $context->getResolverClasses());
		self::assertNull($context->getFieldMap());
		self::assertFalse($context->isCollection());
		self::assertSame(SpyArrayMapper::class, $updated->getMapperClass());
		self::assertSame([SpyResolver::class], $updated->getResolverClasses());
		self::assertSame($fieldMap, $updated->getFieldMap());
		self::assertTrue($updated->isCollection());
	}
}
