<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query\Result\Parser;

use ON\Data\Query\Result\Parser\RootNode;
use PHPUnit\Framework\TestCase;

final class IdentityEncodingTest extends TestCase
{
	public function testIdentityEncodingKeepsScalarTypesDistinct(): void
	{
		$node = new RootNode(['id', 'label'], ['id']);

		foreach ([
			[1, 'int'],
			['1', 'canonical-integer-string'], // same key as int 1
			[1.0, 'float'],
			[true, 'bool-true'],
			[false, 'bool-false'],
			[0, 'int-zero'],
			['', 'empty-string'],
			['01', 'leading-zero-string'],
		] as $row) {
			$node->parseRow(0, $row);
		}

		$result = $node->getResult();

		self::assertCount(7, $result);
		self::assertSame('int', $result[0]['label']);
	}

	public function testCanonicalIntegerStringsShareIdentityWithInts(): void
	{
		$node = new RootNode(['id', 'label'], ['id']);

		$node->parseRow(0, [5, 'from-int']);
		$node->parseRow(0, ['5', 'from-string']);

		self::assertSame([['id' => 5, 'label' => 'from-int']], $node->getResult());
	}

	public function testBinaryStringsAndSeparatorsDoNotCollide(): void
	{
		$node = new RootNode(['id', 'label'], ['id']);

		foreach ([["a\0b", 'binary'], ['1:2|3', 'separator'], ['1:2', 'plain']] as $row) {
			$node->parseRow(0, $row);
		}

		self::assertCount(3, $node->getResult());
	}

	public function testCompositeIdentityComponentBoundariesDoNotCollide(): void
	{
		$node = new RootNode(['tenant_id', 'id', 'label'], ['tenant_id', 'id']);

		foreach ([[1, 23, 'first'], [12, 3, 'second']] as $row) {
			$node->parseRow(0, $row);
		}

		self::assertCount(2, $node->getResult());
	}

	public function testNullRootIdentitySkipsTheRow(): void
	{
		$node = new RootNode(['id', 'label'], ['id']);

		$node->parseRow(0, [null, 'missing']);
		$node->parseRow(0, [1, 'present']);

		self::assertSame([['id' => 1, 'label' => 'present']], $node->getResult());
	}
}
