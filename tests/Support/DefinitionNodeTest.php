<?php

declare(strict_types=1);

namespace Tests\ON\Data\Support;

use ON\Data\Support\DefinitionNode;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\PlainDataAsserts;

final class DefinitionNodeTest extends TestCase
{
	use PlainDataAsserts;

	public function testConstructsFromAnArrayAndReturnsAllItems(): void
	{
		$node = new DefinitionNode([
			'table' => 'users',
			'primaryKey' => ['id'],
		]);

		self::assertSame(
			[
				'table' => 'users',
				'primaryKey' => ['id'],
			],
			$node->all(),
		);
	}

	public function testRetrievesTopLevelAndNestedValues(): void
	{
		$node = new DefinitionNode([
			'table' => 'users',
			'metadata' => [
				'hidden' => true,
			],
		]);

		self::assertSame('users', $node->get('table'));
		self::assertTrue($node->get('metadata.hidden'));
	}

	public function testReturnsDefaultForMissingValue(): void
	{
		$node = new DefinitionNode();

		self::assertSame('fallback', $node->get('missing', 'fallback'));
	}

	public function testDetectsExistingValue(): void
	{
		$node = new DefinitionNode([
			'primaryKey' => ['id'],
		]);

		self::assertTrue($node->has('primaryKey'));
		self::assertFalse($node->has('table'));
	}

	public function testSetsTopLevelAndNestedValues(): void
	{
		$node = new DefinitionNode([
			'table' => 'users',
		]);

		$node->set('table', 'app_users');
		$node->set('metadata.hidden', true);

		self::assertSame('app_users', $node->get('table'));
		self::assertTrue($node->get('metadata.hidden'));
	}

	public function testIteratesOverTopLevelValues(): void
	{
		$node = new DefinitionNode([
			'table' => 'users',
			'primaryKey' => ['id'],
		]);

		$items = [];

		foreach ($node as $key => $value) {
			$items[$key] = $value;
		}

		self::assertSame($node->all(), $items);
	}

	public function testJsonSerializeReturnsTheSameArrayAsAll(): void
	{
		$node = new DefinitionNode([
			'table' => 'users',
			'primaryKey' => ['id'],
		]);

		self::assertSame($node->all(), $node->jsonSerialize());
	}

	public function testAllContainsOnlyPlainData(): void
	{
		$node = new DefinitionNode([
			'table' => 'users',
			'metadata' => [
				'hidden' => false,
				'label' => 'Users',
			],
		]);

		self::assertPlainData($node->all());
	}
}
