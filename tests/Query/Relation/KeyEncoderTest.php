<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query\Relation;

use ON\Data\Query\Exception\LoadRuntimeException;
use ON\Data\Query\Relation\KeyEncoder;
use PHPUnit\Framework\TestCase;

final class KeyEncoderTest extends TestCase
{
	public function testIntegerAndCanonicalIntegerStringEncodeIdentically(): void
	{
		self::assertSame(KeyEncoder::encodeValue(42), KeyEncoder::encodeValue('42'));
		self::assertSame(KeyEncoder::encodeValue(-7), KeyEncoder::encodeValue('-7'));
		self::assertSame(KeyEncoder::encodeValue(0), KeyEncoder::encodeValue('0'));
		self::assertNotSame(KeyEncoder::encodeValue(42), KeyEncoder::encodeValue('042'));
	}

	public function testCompositeTuplesDoNotCollideOnDelimiterCharacters(): void
	{
		$left = KeyEncoder::encodeTuple(['a;b', 'c']);
		$right = KeyEncoder::encodeTuple(['a', 'b;c']);

		self::assertNotSame($left, $right);
	}

	public function testScalarTypesAreDistinguished(): void
	{
		self::assertNotSame(KeyEncoder::encodeValue(true), KeyEncoder::encodeValue(1));
		self::assertNotSame(KeyEncoder::encodeValue(false), KeyEncoder::encodeValue(0));
		self::assertNotSame(KeyEncoder::encodeValue(1.5), KeyEncoder::encodeValue('1.5'));
	}

	public function testExtractTupleReturnsNullWhenAnyFieldIsNull(): void
	{
		self::assertNull(KeyEncoder::extractTuple(['tenantId', 'id'], ['tenantId' => 1, 'id' => null]));
		self::assertSame([1, 'ada'], KeyEncoder::extractTuple(['tenantId', 'name'], ['tenantId' => 1, 'name' => 'ada']));
	}

	public function testExtractTupleThrowsWhenFieldIsMissing(): void
	{
		$this->expectException(LoadRuntimeException::class);
		$this->expectExceptionMessage('Configured key field "userId" is missing');

		KeyEncoder::extractTuple(['userId'], ['id' => 1]);
	}

	public function testExtractTupleThrowsWhenValueIsNonScalar(): void
	{
		$this->expectException(LoadRuntimeException::class);
		$this->expectExceptionMessage('Key field "id" must contain a scalar value');

		KeyEncoder::extractTuple(['id'], ['id' => ['nested']]);
	}
}
