<?php

declare(strict_types=1);

namespace Tests\ON\Data\Support;

use ON\Data\Definition\Collection\Collection;
use ON\Data\Definition\Registry;
use PHPUnit\Framework\TestCase;

final class ReferenceBindingTest extends TestCase
{
	public function testRepeatedRootLookupsReturnSameWrapperInstance(): void
	{
		$registry = new Registry([
			'collections' => [
				'users' => Collection::defaultDefinition('users'),
			],
			'views' => [],
		]);

		$first = $registry->getCollection('users');
		$second = $registry->getCollection('users');

		self::assertSame($first, $second);
	}

	public function testRepeatedNestedLookupsReturnSameWrapperInstance(): void
	{
		$registry = new Registry();
		$field = $registry->collection('users')->field('email', 'string');

		self::assertSame($field, $registry->getCollection('users')?->getField('email'));
	}

	public function testReadOnlyLookupsDoNotMutateCanonicalArray(): void
	{
		$registry = new Registry();
		$registry->collection('users')->field('email', 'string');
		$before = $registry->all();

		$registry->getCollection('users');
		$registry->getCollection('users')?->getField('email');

		self::assertSame($before, $registry->all());
	}
}
