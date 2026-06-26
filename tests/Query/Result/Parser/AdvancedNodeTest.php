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

		$root->parseRow(0, [1, 'Ada', 'Writes parsers']);

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

		$posts->parseRow(0, [10, 1, 'First']);

		self::assertSame('First', $root->getResult()[0]['posts'][0]['title']);
		self::assertSame([], $root->getResult()[1]['posts']);
	}

	public function testStaticNodeRejectsParseRowToAvoidDoubleRegistration(): void
	{
		$this->expectException(ParserException::class);

		$root = new StaticNode(['id', 'name'], ['id']);
		$root->parseRow(0, [1, 'Ada']);
	}

	public function testStaticNodeIndexesExistingDataExactlyOnce(): void
	{
		$root = new StaticNode(['id', 'name'], ['id']);
		$root->linkNode('posts', $posts = new CollectionNode(['id', 'user_id', 'title'], ['id'], ['user_id'], ['id']));

		$user = ['id' => 1, 'name' => 'Ada'];
		$root->push($user);

		$posts->parseRow(0, [10, 1, 'First']);
		$posts->parseRow(0, [11, 1, 'Second']);

		self::assertCount(2, $root->getResult()[0]['posts']);
		self::assertSame(['First', 'Second'], array_column($root->getResult()[0]['posts'], 'title'));
	}

	public function testProxyNodeMountsRoleSpecificSingularChildren(): void
	{
		$root = new RootNode(['type', 'owner_id', 'name'], ['type', 'owner_id']);
		$root->linkNode('owner', $proxy = new ProxyNode(['type', 'owner_id']));

		foreach ([['user', 1, 'Comment'], ['team', 5, 'Repo']] as $row) {
			$root->parseRow(0, $row);
		}

		/** @var SingularNode $userNode */
		$userNode = $proxy->addNode('user', new SingularNode(['id', 'name'], ['id'], ['id'], ['type', 'owner_id'], 'user'));
		/** @var SingularNode $teamNode */
		$teamNode = $proxy->addNode('team', new SingularNode(['id', 'name'], ['id'], ['id'], ['type', 'owner_id'], 'team'));

		$userNode->parseRow(0, [1, 'Ada']);
		$teamNode->parseRow(0, [5, 'Core']);

		self::assertSame('Ada', $root->getResult()[0]['owner']['name']);
		self::assertSame('Core', $root->getResult()[1]['owner']['name']);
	}

	public function testParentMergeNodeMergesIntoParentRecords(): void
	{
		$root = new RootNode(['id', 'name'], ['id']);
		$root->linkNode(null, $merge = new ParentMergeNode('employee', ['id', 'title'], ['id'], ['id'], ['id']));

		$root->parseRow(0, [1, 'Ada']);
		$merge->parseRow(0, [1, 'Lead']);
		$root->mergeInheritanceNodes();

		self::assertSame('Lead', $root->getResult()[0]['title']);
	}

	public function testSubclassMergeNodeCanIncludeTheDiscriminatorField(): void
	{
		$root = new RootNode(['id', 'name'], ['id']);
		$root->linkNode(null, $merge = new SubclassMergeNode('manager', ['id', 'department'], ['id'], ['id'], ['id']));

		$root->parseRow(0, [1, 'Ada']);
		$merge->parseRow(0, [1, 'Platform']);
		$root->mergeInheritanceNodes(true);

		self::assertSame('Platform', $root->getResult()[0]['department']);
		self::assertSame('manager', $root->getResult()[0]['@role']);
	}
}
