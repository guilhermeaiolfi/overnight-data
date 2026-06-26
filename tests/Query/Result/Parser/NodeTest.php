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

		foreach ([[1, 'email@gmail.com'], [2, 'other@gmail.com']] as $row) {
			$node->parseRow(0, $row);
		}

		self::assertSame([
			['id' => 1, 'email' => 'email@gmail.com'],
			['id' => 2, 'email' => 'other@gmail.com'],
		], $node->getResult());
	}

	public function testRootDuplicate(): void
	{
		$node = new RootNode(['id', 'email'], ['id']);

		foreach ([[1, 'email@gmail.com'], [2, 'other@gmail.com'], [1, 'other@gmail.com'], [2, 'other@gmail.com']] as $row) {
			$node->parseRow(0, $row);
		}

		self::assertSame([
			['id' => 1, 'email' => 'email@gmail.com'],
			['id' => 2, 'email' => 'other@gmail.com'],
		], $node->getResult());
	}

	public function testJoinedSingular(): void
	{
		$node = new RootNode(['id', 'email'], ['id']);
		$node->joinNode('balance', $this->createSingularNode());

		foreach ([[1, 'email@gmail.com', 1, 1, 100], [2, 'other@gmail.com', 2, 2, 200], [3, 'third@gmail.com', null, null, null]] as $row) {
			$node->parseRow(0, $row);
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

		foreach ([[1, 'email@gmail.com'], [2, 'other@gmail.com'], [3, 'third@gmail.com']] as $row) {
			$node->parseRow(0, $row);
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

		foreach ([[1, 'email@gmail.com'], [2, 'other@gmail.com'], [3, 'third@gmail.com']] as $row) {
			$node->parseRow(0, $row);
		}

		foreach ([[1, 1, 100], [2, 2, 200]] as $row) {
			$child->parseRow(0, $row);
		}

		self::assertSame([
			['id' => 1, 'email' => 'email@gmail.com', 'balance' => ['id' => 1, 'user_id' => 1, 'balance' => 100]],
			['id' => 2, 'email' => 'other@gmail.com', 'balance' => ['id' => 2, 'user_id' => 2, 'balance' => 200]],
			['id' => 3, 'email' => 'third@gmail.com', 'balance' => null],
		], $node->getResult());
	}

	public function testSingularInvalidReference(): void
	{
		$this->expectException(ParserException::class);

		$node = new RootNode(['id', 'email'], ['id']);
		$node->linkNode('balance', $child = $this->createSingularNode());

		foreach ([[1, 'email@gmail.com'], [2, 'other@gmail.com'], [3, 'third@gmail.com']] as $row) {
			$node->parseRow(0, $row);
		}

		foreach ([[1, 1, 100], [2, -1, 200]] as $row) {
			$child->parseRow(0, $row);
		}
	}

	public function testInvalidColumnCount(): void
	{
		$this->expectException(ParserException::class);

		$node = new RootNode(['id', 'email'], ['id']);
		$node->joinNode('balance', $this->createSingularNode());

		foreach ([[1, 'email@gmail.com', 1, 1, 100], [2, 'other@gmail.com', 2, 2], [3, 'third@gmail.com', null, null, null]] as $row) {
			$node->parseRow(0, $row);
		}
	}

	public function testGetNode(): void
	{
		$node = new RootNode(['id', 'email'], ['id']);
		$node->joinNode('balance', $this->createSingularNode());

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

		$this->createSingularNode()->parseRow(0, [1, 10, 10]);
	}

	public function testJoinedCollection(): void
	{
		$node = new RootNode(['id', 'email'], ['id']);
		$node->joinNode('lines', new CollectionNode(['id', 'user_id', 'value'], ['id'], ['user_id'], ['id']));

		foreach ([[1, 'email@gmail.com', 1, 1, 100], [2, 'other@gmail.com', 2, 2, 200], [2, 'other@gmail.com', 3, 2, 300], [3, 'third@gmail.com', null, null, null], [3, 'third@gmail.com', null, null, null]] as $row) {
			$node->parseRow(0, $row);
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

		foreach ([[1, 'email@gmail.com'], [2, 'other@gmail.com'], [3, 'third@gmail.com']] as $row) {
			$node->parseRow(0, $row);
		}

		$this->expectException(ParserException::class);

		foreach ([[1, 1, 100], [2, -1, 200]] as $row) {
			$child->parseRow(0, $row);
		}
	}

	public function testCollectionParseWithoutParent(): void
	{
		$this->expectException(ParserException::class);

		(new CollectionNode(['id', 'user_id', 'balance'], ['id'], ['user_id'], ['id']))->parseRow(0, [1, 10, 10]);
	}

	private function createSingularNode(): SingularNode
	{
		return new SingularNode(['id', 'user_id', 'balance'], ['id'], ['user_id'], ['id']);
	}
}
