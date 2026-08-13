<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query\Result\Parser;

use ON\Data\Query\Result\Parser\CollectionNode;
use ON\Data\Query\Result\Parser\ParserException;
use ON\Data\Query\Result\Parser\RootNode;
use ON\Data\Query\Result\Parser\SingularNode;
use PHPUnit\Framework\TestCase;

final class NodeTest extends TestCase
{
	public function testRoot(): void
	{
		$node = new RootNode(['id', 'email'], ['id']);

		foreach ([
			['id' => 1, 'email' => 'email@gmail.com'],
			['id' => 2, 'email' => 'other@gmail.com'],
		] as $row) {
			$node->parseRow($row);
		}

		self::assertSame([
			['id' => 1, 'email' => 'email@gmail.com'],
			['id' => 2, 'email' => 'other@gmail.com'],
		], $node->getResult());
	}

	public function testRootDuplicate(): void
	{
		$node = new RootNode(['id', 'email'], ['id']);

		foreach ([
			['id' => 1, 'email' => 'email@gmail.com'],
			['id' => 2, 'email' => 'other@gmail.com'],
			['id' => 1, 'email' => 'other@gmail.com'],
			['id' => 2, 'email' => 'other@gmail.com'],
		] as $row) {
			$node->parseRow($row);
		}

		self::assertSame([
			['id' => 1, 'email' => 'email@gmail.com'],
			['id' => 2, 'email' => 'other@gmail.com'],
		], $node->getResult());
	}

	public function testJoinedSingular(): void
	{
		$node = new RootNode(['id', 'email'], ['id']);
		$node->joinNode('balance', $this->createJoinedBalanceNode());

		foreach ([
			['id' => 1, 'email' => 'email@gmail.com', 'balance.id' => 1, 'balance.user_id' => 1, 'balance.balance' => 100],
			['id' => 2, 'email' => 'other@gmail.com', 'balance.id' => 2, 'balance.user_id' => 2, 'balance.balance' => 200],
			['id' => 3, 'email' => 'third@gmail.com', 'balance.id' => null, 'balance.user_id' => null, 'balance.balance' => null],
		] as $row) {
			$node->parseRow($row);
		}

		self::assertSame([
			['id' => 1, 'email' => 'email@gmail.com', 'balance' => ['id' => 1, 'user_id' => 1, 'balance' => 100]],
			['id' => 2, 'email' => 'other@gmail.com', 'balance' => ['id' => 2, 'user_id' => 2, 'balance' => 200]],
			['id' => 3, 'email' => 'third@gmail.com', 'balance' => null],
		], $node->getResult());
	}

	public function testGetReferences(): void
	{
		$node = new RootNode(['id', 'email'], ['id']);
		$node->linkNode('balance', $child = $this->createSingularNode());

		foreach ([
			['id' => 1, 'email' => 'email@gmail.com'],
			['id' => 2, 'email' => 'other@gmail.com'],
			['id' => 3, 'email' => 'third@gmail.com'],
		] as $row) {
			$node->parseRow($row);
		}

		self::assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $child->getReferenceValues());
	}

	public function testGetReferencesWithoutParent(): void
	{
		$this->expectException(ParserException::class);

		$this->createSingularNode()->getReferenceValues();
	}

	public function testLinkedSingular(): void
	{
		$node = new RootNode(['id', 'email'], ['id']);
		$node->linkNode('balance', $child = $this->createSingularNode());

		foreach ([
			['id' => 1, 'email' => 'email@gmail.com'],
			['id' => 2, 'email' => 'other@gmail.com'],
			['id' => 3, 'email' => 'third@gmail.com'],
		] as $row) {
			$node->parseRow($row);
		}

		foreach ([
			['id' => 1, 'user_id' => 1, 'balance' => 100],
			['id' => 2, 'user_id' => 2, 'balance' => 200],
		] as $row) {
			$child->parseRow($row);
		}

		self::assertSame([
			['id' => 1, 'email' => 'email@gmail.com', 'balance' => ['id' => 1, 'user_id' => 1, 'balance' => 100]],
			['id' => 2, 'email' => 'other@gmail.com', 'balance' => ['id' => 2, 'user_id' => 2, 'balance' => 200]],
			['id' => 3, 'email' => 'third@gmail.com', 'balance' => null],
		], $node->getResult());
	}

	public function testSingularInvalidReference(): void
	{
		$node = new RootNode(['id', 'email'], ['id']);
		$node->linkNode('balance', $child = $this->createSingularNode());

		foreach ([
			['id' => 1, 'email' => 'email@gmail.com'],
			['id' => 2, 'email' => 'other@gmail.com'],
			['id' => 3, 'email' => 'third@gmail.com'],
		] as $row) {
			$node->parseRow($row);
		}

		foreach ([
			['id' => 1, 'user_id' => 1, 'balance' => 100],
			['id' => 2, 'user_id' => -1, 'balance' => 200],
		] as $row) {
			$child->parseRow($row);
		}

		self::assertSame([
			['id' => 1, 'email' => 'email@gmail.com', 'balance' => ['id' => 1, 'user_id' => 1, 'balance' => 100]],
			['id' => 2, 'email' => 'other@gmail.com', 'balance' => null],
			['id' => 3, 'email' => 'third@gmail.com', 'balance' => null],
		], $node->getResult());
	}

	public function testMissingJoinedChildKeysAreTreatedAsNull(): void
	{
		$node = new RootNode(['id', 'email'], ['id']);
		$node->joinNode('balance', $this->createJoinedBalanceNode());

		$node->parseRow(['id' => 1, 'email' => 'email@gmail.com']);

		self::assertSame([
			['id' => 1, 'email' => 'email@gmail.com', 'balance' => null],
		], $node->getResult());
	}

	public function testGetNode(): void
	{
		$node = new RootNode(['id', 'email'], ['id']);
		$node->joinNode('balance', $this->createJoinedBalanceNode());

		self::assertInstanceOf(SingularNode::class, $node->getNode('balance'));
	}

	public function testGetUndefinedNode(): void
	{
		$this->expectException(ParserException::class);

		(new RootNode(['id', 'email'], ['id']))->getNode('balance');
	}

	public function testSingularParseWithoutParent(): void
	{
		$this->expectException(ParserException::class);

		$this->createSingularNode()->parseRow(['id' => 1, 'user_id' => 10, 'balance' => 10]);
	}

	public function testJoinedCollection(): void
	{
		$node = new RootNode(['id', 'email'], ['id']);
		$node->joinNode('lines', new CollectionNode(
			['id' => 'lines.id', 'user_id' => 'lines.user_id', 'value' => 'lines.value'],
			['id'],
			['user_id'],
			['id'],
		));

		foreach ([
			['id' => 1, 'email' => 'email@gmail.com', 'lines.id' => 1, 'lines.user_id' => 1, 'lines.value' => 100],
			['id' => 2, 'email' => 'other@gmail.com', 'lines.id' => 2, 'lines.user_id' => 2, 'lines.value' => 200],
			['id' => 2, 'email' => 'other@gmail.com', 'lines.id' => 3, 'lines.user_id' => 2, 'lines.value' => 300],
			['id' => 3, 'email' => 'third@gmail.com', 'lines.id' => null, 'lines.user_id' => null, 'lines.value' => null],
			['id' => 3, 'email' => 'third@gmail.com', 'lines.id' => null, 'lines.user_id' => null, 'lines.value' => null],
		] as $row) {
			$node->parseRow($row);
		}

		self::assertSame([
			['id' => 1, 'email' => 'email@gmail.com', 'lines' => [['id' => 1, 'user_id' => 1, 'value' => 100]]],
			['id' => 2, 'email' => 'other@gmail.com', 'lines' => [['id' => 2, 'user_id' => 2, 'value' => 200], ['id' => 3, 'user_id' => 2, 'value' => 300]]],
			['id' => 3, 'email' => 'third@gmail.com', 'lines' => []],
		], $node->getResult());
	}

	public function testCollectionInvalidReference(): void
	{
		$node = new RootNode(['id', 'email'], ['id']);
		$node->linkNode('balance', $child = new CollectionNode(['id', 'user_id', 'balance'], ['id'], ['user_id'], ['id']));

		foreach ([
			['id' => 1, 'email' => 'email@gmail.com'],
			['id' => 2, 'email' => 'other@gmail.com'],
			['id' => 3, 'email' => 'third@gmail.com'],
		] as $row) {
			$node->parseRow($row);
		}

		foreach ([
			['id' => 1, 'user_id' => 1, 'balance' => 100],
			['id' => 2, 'user_id' => -1, 'balance' => 200],
		] as $row) {
			$child->parseRow($row);
		}

		self::assertSame([
			['id' => 1, 'email' => 'email@gmail.com', 'balance' => [['id' => 1, 'user_id' => 1, 'balance' => 100]]],
			['id' => 2, 'email' => 'other@gmail.com', 'balance' => []],
			['id' => 3, 'email' => 'third@gmail.com', 'balance' => []],
		], $node->getResult());
	}

	public function testCollectionParseWithoutParent(): void
	{
		$this->expectException(ParserException::class);

		(new CollectionNode(['id', 'user_id', 'balance'], ['id'], ['user_id'], ['id']))->parseRow([
			'id' => 1,
			'user_id' => 10,
			'balance' => 10,
		]);
	}

	private function createSingularNode(): SingularNode
	{
		return new SingularNode(['id', 'user_id', 'balance'], ['id'], ['user_id'], ['id']);
	}

	private function createJoinedBalanceNode(): SingularNode
	{
		return new SingularNode(
			['id' => 'balance.id', 'user_id' => 'balance.user_id', 'balance' => 'balance.balance'],
			['id'],
			['user_id'],
			['id'],
		);
	}
}
