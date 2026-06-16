<?php

declare(strict_types=1);

namespace Tests\ON\Data\Support;

use ON\Data\Support\Dot;
use PHPUnit\Framework\TestCase;

final class DotTest extends TestCase
{
	public function testReadsAnExistingTopLevelKey(): void
	{
		$dot = new Dot(['table' => 'users']);

		self::assertSame('users', $dot->get('table'));
	}

	public function testReadsAnExistingNestedKey(): void
	{
		$dot = new Dot([
			'collections' => [
				'users' => [
					'table' => 'users',
				],
			],
		]);

		self::assertSame('users', $dot->get('collections.users.table'));
	}

	public function testReturnsDefaultForMissingKey(): void
	{
		$dot = new Dot();

		self::assertSame('default', $dot->get('collections.users.table', 'default'));
	}

	public function testDistinguishesNullFromMissingPath(): void
	{
		$dot = new Dot([
			'collections' => [
				'users' => [
					'note' => null,
				],
			],
		]);

		self::assertNull($dot->get('collections.users.note', 'fallback'));
		self::assertTrue($dot->has('collections.users.note'));
		self::assertFalse($dot->has('collections.users.missing'));
	}

	public function testChecksExistingAndMissingPaths(): void
	{
		$dot = new Dot([
			'collections' => [
				'users' => [
					'table' => 'users',
				],
			],
		]);

		self::assertTrue($dot->has('collections.users.table'));
		self::assertFalse($dot->has('collections.posts.table'));
	}

	public function testSetsATopLevelKey(): void
	{
		$dot = new Dot();
		$dot->set('table', 'users');

		self::assertSame('users', $dot->get('table'));
	}

	public function testSetsANestedKeyAndCreatesMissingIntermediateArrays(): void
	{
		$dot = new Dot();
		$dot->set('collections.users.primaryKey', ['id']);

		self::assertSame(
			[
				'collections' => [
					'users' => [
						'primaryKey' => ['id'],
					],
				],
			],
			$dot->all(),
		);
	}

	public function testOverwritesExistingValueAndPreservesUnrelatedValues(): void
	{
		$dot = new Dot([
			'collections' => [
				'users' => [
					'table' => 'users',
					'database' => 'default',
				],
			],
		]);

		$dot->set('collections.users.table', 'app_users');

		self::assertSame('app_users', $dot->get('collections.users.table'));
		self::assertSame('default', $dot->get('collections.users.database'));
	}

	public function testDeletesATopLevelOrNestedPath(): void
	{
		$dot = new Dot([
			'table' => 'users',
			'collections' => [
				'users' => [
					'primaryKey' => ['id'],
				],
			],
		]);

		$dot->delete(['table', 'collections.users.primaryKey']);

		self::assertFalse($dot->has('table'));
		self::assertFalse($dot->has('collections.users.primaryKey'));
	}

	public function testReturnsRootArrayWhenPathIsNull(): void
	{
		$items = ['table' => 'users'];
		$dot = new Dot($items);

		self::assertSame($items, $dot->get());
	}
}
