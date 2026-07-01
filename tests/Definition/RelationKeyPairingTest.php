<?php

declare(strict_types=1);

namespace Tests\ON\Data\Definition;

use InvalidArgumentException;
use ON\Data\Definition\Relation\RelationKeyPairing;
use PHPUnit\Framework\TestCase;

final class RelationKeyPairingTest extends TestCase
{
	public function testFromPreservesOrderedPairs(): void
	{
		$pairing = RelationKeyPairing::from(['tenant_id', 'slug'], ['article_tenant_id', 'article_slug']);

		self::assertSame(['tenant_id', 'slug'], $pairing->getLeftFields());
		self::assertSame(['article_tenant_id', 'article_slug'], $pairing->getRightFields());
		self::assertSame([
			['left' => 'tenant_id', 'right' => 'article_tenant_id'],
			['left' => 'slug', 'right' => 'article_slug'],
		], $pairing->getPairs());
		self::assertSame(2, $pairing->count());
		self::assertTrue($pairing->isComposite());
	}

	public function testFromRejectsMismatchedCounts(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('count mismatch');

		RelationKeyPairing::from(['id'], ['tenant_id', 'slug']);
	}

	public function testFromRejectsEmptySides(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('at least one left field');

		RelationKeyPairing::from([], ['id']);
	}

	public function testReverseSwapsSidesAndIsCached(): void
	{
		$pairing = RelationKeyPairing::from(['tenant_id', 'slug'], ['article_tenant_id', 'article_slug']);
		$reversed = $pairing->reverse();

		self::assertSame(['article_tenant_id', 'article_slug'], $reversed->getLeftFields());
		self::assertSame(['tenant_id', 'slug'], $reversed->getRightFields());
		self::assertSame($reversed, $pairing->reverse());
		self::assertSame($pairing, $reversed->reverse());
	}
}
