<?php

declare(strict_types=1);

namespace Tests\ON\Data\Support;

use LogicException;
use ON\Data\Definition\Collection\Collection;
use ON\Data\Definition\Registry;
use ON\Data\Support\DefinitionNode;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\PlainDataAsserts;

final class DefinitionNodeTest extends TestCase
{
	use PlainDataAsserts;

	public function testCreateDefinitionMergesAssociativeArraysAndReplacesLists(): void
	{
		$definition = TestDefaultDefinitionNode::createDefinition([
			'primaryKey' => ['tenant_id', 'id'],
			'metadata' => [
				'label' => 'Users',
			],
		]);

		self::assertSame(
			[
				'class' => TestDefaultDefinitionNode::class,
				'primaryKey' => ['tenant_id', 'id'],
				'metadata' => [
					'visible' => true,
					'label' => 'Users',
				],
			],
			$definition,
		);
	}

	public function testWrapperBindsDirectlyToFinalArraySlot(): void
	{
		$registry = new Registry();
		$registry->collection('users', TestCollectionNode::class);
		$collection = $registry->getCollection('users');

		self::assertInstanceOf(TestCollectionNode::class, $collection);
		self::assertSame('users', $collection->getName());

		$collection->table('app_users');

		self::assertSame('app_users', $registry->all()['collections']['users']['table']);
		self::assertPlainData($registry->all());
	}

	public function testDefinitionNodesCannotBeCloned(): void
	{
		$node = (new Registry())->collection('users', TestCollectionNode::class);

		$this->expectException(LogicException::class);
		clone $node;
	}
}

final class TestDefaultDefinitionNode extends DefinitionNode
{
	protected static function definitionDefaults(): array
	{
		return [
			'class' => static::class,
			'primaryKey' => ['id'],
			'metadata' => [
				'visible' => true,
			],
		];
	}
}

final class TestCollectionNode extends Collection
{
}
