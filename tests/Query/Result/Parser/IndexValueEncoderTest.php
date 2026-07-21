<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query\Result\Parser;

use ON\Data\Query\Result\Parser\IndexValueEncoder;
use ON\Data\Query\Result\Parser\ReferenceIndex;
use ON\Data\Query\Result\Parser\RootNode;
use ON\Data\Query\Result\Parser\SingularNode;
use PHPUnit\Framework\TestCase;

final class IndexValueEncoderTest extends TestCase
{
	public function testIntegerAndCanonicalIntegerStringShareTheSameIndexKey(): void
	{
		self::assertSame(
			IndexValueEncoder::encodeIndexValue(5),
			IndexValueEncoder::encodeIndexValue('5'),
		);
		self::assertSame(
			IndexValueEncoder::encodeIndexValue(0),
			IndexValueEncoder::encodeIndexValue('0'),
		);
		self::assertSame(
			IndexValueEncoder::encodeIndexValue(-12),
			IndexValueEncoder::encodeIndexValue('-12'),
		);
	}

	public function testNonIntegerStringsKeepDistinctTypePrefixedKeys(): void
	{
		self::assertSame('s:8:homepage', IndexValueEncoder::encodeIndexValue('homepage'));
		self::assertNotSame(
			IndexValueEncoder::encodeIndexValue(10),
			IndexValueEncoder::encodeIndexValue('010'),
		);
	}

	public function testReferenceIndexResolvesIntParentAgainstStringChildCriteria(): void
	{
		$index = new ReferenceIndex(['id']);
		$parent = ['id' => 42, 'title' => 'News'];
		$index->add($parent);

		self::assertSame([$parent], $index->getRecordsByValues(['42']));
		self::assertSame([$parent], $index->getRecordsByValues([42]));
	}

	public function testSingularNodeMountsWhenParentIdIsIntAndChildForeignKeyIsString(): void
	{
		$root = new RootNode(['id', 'title'], ['id']);
		$cover = new SingularNode(
			['id', 'news_id', 'sequence'],
			['id'],
			['news_id'],
			['id'],
		);
		$root->linkNode('cover', $cover);

		$root->parseRow(0, [7, 'Hello']);
		$cover->parseRow(0, [100, '7', 0]);

		$result = $root->getResult();

		self::assertCount(1, $result);
		self::assertIsArray($result[0]['cover'] ?? null);
		self::assertSame(100, $result[0]['cover']['id']);
		self::assertSame('7', $result[0]['cover']['news_id']);
	}
}
