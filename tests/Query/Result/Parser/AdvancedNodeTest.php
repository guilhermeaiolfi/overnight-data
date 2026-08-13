<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query\Result\Parser;

use ON\Data\Query\Result\Parser\CollectionNode;
use ON\Data\Query\Result\Parser\EmbeddedNode;
use ON\Data\Query\Result\Parser\ParentMergeNode;
use ON\Data\Query\Result\Parser\ParserException;
use ON\Data\Query\Result\Parser\ProxyNode;
use ON\Data\Query\Result\Parser\RootNode;
use ON\Data\Query\Result\Parser\SingularNode;
use ON\Data\Query\Result\Parser\StaticNode;
use ON\Data\Query\Result\Parser\SubclassMergeNode;
use PHPUnit\Framework\TestCase;

final class AdvancedNodeTest extends TestCase
{
	public function testEmbeddedNodeMountsIntoTheMostRecentlyParsedParent(): void
	{
		$root = new RootNode(['id', 'name'], ['id']);
		$root->joinNode('profile', new EmbeddedNode(['bio'], ['id']));

		$root->parseRow(['id' => 1, 'name' => 'Ada', 'bio' => 'Writes parsers']);

		self::assertSame('Writes parsers', $root->getResult()[0]['profile']['bio']);
	}

	public function testStaticNodeAcceptsExistingDataAndRegistersReferenceIndexes(): void
	{
		$root = new StaticNode(['id', 'name'], ['id']);
		$root->linkNode('posts', $posts = new CollectionNode(['id', 'user_id', 'title'], ['id'], ['user_id'], ['id']));

		$first = ['id' => 1, 'name' => 'Ada'];
		$second = ['id' => 2, 'name' => 'Linus'];
		$root->push($first);
		$root->push($second);

		$posts->parseRow(['id' => 10, 'user_id' => 1, 'title' => 'First']);

		self::assertSame('First', $root->getResult()[0]['posts'][0]['title']);
		self::assertSame([], $root->getResult()[1]['posts']);
	}

	public function testStaticNodeRejectsParseRowToAvoidDoubleRegistration(): void
	{
		$this->expectException(ParserException::class);

		$root = new StaticNode(['id', 'name'], ['id']);
		$root->parseRow(['id' => 1, 'name' => 'Ada']);
	}

	public function testStaticNodeIndexesExistingDataExactlyOnce(): void
	{
		$root = new StaticNode(['id', 'name'], ['id']);
		$root->linkNode('posts', $posts = new CollectionNode(['id', 'user_id', 'title'], ['id'], ['user_id'], ['id']));

		$user = ['id' => 1, 'name' => 'Ada'];
		$root->push($user);

		$posts->parseRow(['id' => 10, 'user_id' => 1, 'title' => 'First']);
		$posts->parseRow(['id' => 11, 'user_id' => 1, 'title' => 'Second']);

		self::assertCount(2, $root->getResult()[0]['posts']);
		self::assertSame(['First', 'Second'], array_column($root->getResult()[0]['posts'], 'title'));
	}

	public function testProxyNodeMountsRoleSpecificSingularChildren(): void
	{
		$root = new RootNode(['type', 'owner_id', 'name'], ['type', 'owner_id']);
		$root->linkNode('owner', $proxy = new ProxyNode(['type', 'owner_id']));

		foreach ([
			['type' => 'user', 'owner_id' => 1, 'name' => 'Comment'],
			['type' => 'team', 'owner_id' => 5, 'name' => 'Repo'],
		] as $row) {
			$root->parseRow($row);
		}

		/** @var SingularNode $userNode */
		$userNode = $proxy->addNode('user', new SingularNode(['id', 'name'], ['id'], ['id'], ['type', 'owner_id'], 'user'));
		/** @var SingularNode $teamNode */
		$teamNode = $proxy->addNode('team', new SingularNode(['id', 'name'], ['id'], ['id'], ['type', 'owner_id'], 'team'));

		$userNode->parseRow(['id' => 1, 'name' => 'Ada']);
		$teamNode->parseRow(['id' => 5, 'name' => 'Core']);

		self::assertSame('Ada', $root->getResult()[0]['owner']['name']);
		self::assertSame('Core', $root->getResult()[1]['owner']['name']);
	}

	public function testParentMergeNodeMergesIntoParentRecords(): void
	{
		$root = new RootNode(['id', 'name'], ['id']);
		$root->linkNode(null, $merge = new ParentMergeNode('employee', ['id', 'title'], ['id'], ['id'], ['id']));

		$root->parseRow(['id' => 1, 'name' => 'Ada']);
		$merge->parseRow(['id' => 1, 'title' => 'Lead']);
		$root->mergeInheritanceNodes();

		self::assertSame('Lead', $root->getResult()[0]['title']);
	}

	public function testSubclassMergeNodeCanIncludeTheDiscriminatorField(): void
	{
		$root = new RootNode(['id', 'name'], ['id']);
		$root->linkNode(null, $merge = new SubclassMergeNode('manager', ['id', 'department'], ['id'], ['id'], ['id']));

		$root->parseRow(['id' => 1, 'name' => 'Ada']);
		$merge->parseRow(['id' => 1, 'department' => 'Platform']);
		$root->mergeInheritanceNodes(true);

		self::assertSame('Platform', $root->getResult()[0]['department']);
		self::assertSame('manager', $root->getResult()[0]['@role']);
	}
}
