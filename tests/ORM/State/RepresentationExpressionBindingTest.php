<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RepresentationExpressionBinding;
use PHPUnit\Framework\TestCase;

final class RepresentationExpressionBindingTest extends TestCase
{
	public function testStoresPathAndNullableSelectionKey(): void
	{
		$withoutSelectionKey = new RepresentationExpressionBinding('postCount');
		$withSelectionKey = new RepresentationExpressionBinding('totalPosts', 'total_posts');

		self::assertSame('postCount', $withoutSelectionKey->getPath());
		self::assertNull($withoutSelectionKey->getSelectionKey());
		self::assertSame('totalPosts', $withSelectionKey->getPath());
		self::assertSame('total_posts', $withSelectionKey->getSelectionKey());
	}

	public function testRejectsEmptyPath(): void
	{
		$this->expectException(StateException::class);
		$this->expectExceptionMessage('path');

		new RepresentationExpressionBinding('');
	}

	public function testRejectsEmptySelectionKeyWhenProvided(): void
	{
		$this->expectException(StateException::class);
		$this->expectExceptionMessage('selection key');

		new RepresentationExpressionBinding('postCount', '');
	}

	public function testIsAlwaysReadOnly(): void
	{
		$binding = new RepresentationExpressionBinding('postCount');

		self::assertFalse($binding->isWritable());
		self::assertTrue($binding->isReadOnly());
	}
}
