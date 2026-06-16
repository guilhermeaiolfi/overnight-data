<?php

declare(strict_types=1);

namespace Tests\ON\Data\Support;

use ON\Data\Support\DefinitionNode;
use PHPUnit\Framework\TestCase;

final class ReferenceBindingTest extends TestCase
{
	public function testChangesThroughBoundNodeUpdateTheOriginalArray(): void
	{
		$root = [
			'collections' => [
				'users' => [
					'table' => 'users',
				],
			],
		];

		$node = TestDefinitionNode::fromReference($root['collections']['users']);
		$node->set('table', 'app_users');

		self::assertSame('app_users', $root['collections']['users']['table']);
	}

	public function testChangesThroughTheOriginalArrayAreVisibleThroughNode(): void
	{
		$root = [
			'collections' => [
				'users' => [
					'table' => 'users',
				],
			],
		];

		$node = TestDefinitionNode::fromReference($root['collections']['users']);
		$root['collections']['users']['table'] = 'users_v2';

		self::assertSame('users_v2', $node->get('table'));
	}

	public function testTwoWrappersBoundToTheSameArrayObserveTheSameValues(): void
	{
		$items = [
			'table' => 'users',
		];

		$first = TestDefinitionNode::fromReference($items);
		$second = TestDefinitionNode::fromReference($items);

		$first->set('table', 'app_users');

		self::assertSame('app_users', $second->get('table'));
	}

	public function testReplacingScalarAndNestedArrayWorks(): void
	{
		$items = [
			'table' => 'users',
			'metadata' => [
				'hidden' => false,
			],
		];

		$node = TestDefinitionNode::fromReference($items);
		$node->set('table', 'app_users');
		$node->set('metadata', ['hidden' => true, 'label' => 'Users']);

		self::assertSame('app_users', $items['table']);
		self::assertSame(['hidden' => true, 'label' => 'Users'], $items['metadata']);
	}

	public function testUnrelatedSiblingNodesRemainUnaffected(): void
	{
		$root = [
			'collections' => [
				'users' => [
					'table' => 'users',
				],
				'posts' => [
					'table' => 'posts',
				],
			],
		];

		$users = TestDefinitionNode::fromReference($root['collections']['users']);
		$users->set('table', 'app_users');

		self::assertSame('posts', $root['collections']['posts']['table']);
	}

	public function testOrdinaryRootNodeStillOwnsItsSuppliedArrayNormally(): void
	{
		$items = [
			'table' => 'users',
		];

		$node = new DefinitionNode($items);
		$node->set('table', 'app_users');

		self::assertSame('users', $items['table']);
		self::assertSame('app_users', $node->get('table'));
	}
}

final class TestDefinitionNode extends DefinitionNode
{
}
