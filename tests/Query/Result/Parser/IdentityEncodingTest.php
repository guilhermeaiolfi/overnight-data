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
			['id' => 1, 'label' => 'int'],
			['id' => '1', 'label' => 'canonical-integer-string'], // same key as int 1
			['id' => 1.0, 'label' => 'float'],
			['id' => true, 'label' => 'bool-true'],
			['id' => false, 'label' => 'bool-false'],
			['id' => 0, 'label' => 'int-zero'],
			['id' => '', 'label' => 'empty-string'],
			['id' => '01', 'label' => 'leading-zero-string'],
		] as $row) {
			$node->parseRow($row);
		}

		$result = $node->getResult();

		self::assertCount(7, $result);
		self::assertSame('int', $result[0]['label']);
	}

	public function testCanonicalIntegerStringsShareIdentityWithInts(): void
	{
		$node = new RootNode(['id', 'label'], ['id']);

		$node->parseRow(['id' => 5, 'label' => 'from-int']);
		$node->parseRow(['id' => '5', 'label' => 'from-string']);

		self::assertSame([['id' => 5, 'label' => 'from-int']], $node->getResult());
	}

	public function testBinaryStringsAndSeparatorsDoNotCollide(): void
	{
		$node = new RootNode(['id', 'label'], ['id']);

		foreach ([
			['id' => "a\0b", 'label' => 'binary'],
			['id' => '1:2|3', 'label' => 'separator'],
			['id' => '1:2', 'label' => 'plain'],
		] as $row) {
			$node->parseRow($row);
		}

		self::assertCount(3, $node->getResult());
	}

	public function testCompositeIdentityComponentBoundariesDoNotCollide(): void
	{
		$node = new RootNode(['tenant_id', 'id', 'label'], ['tenant_id', 'id']);

		foreach ([
			['tenant_id' => 1, 'id' => 23, 'label' => 'first'],
			['tenant_id' => 12, 'id' => 3, 'label' => 'second'],
		] as $row) {
			$node->parseRow($row);
		}

		self::assertCount(2, $node->getResult());
	}

	public function testNullRootIdentitySkipsTheRow(): void
	{
		$node = new RootNode(['id', 'label'], ['id']);

		$node->parseRow(['id' => null, 'label' => 'missing']);
		$node->parseRow(['id' => 1, 'label' => 'present']);

		self::assertSame([['id' => 1, 'label' => 'present']], $node->getResult());
	}
}
