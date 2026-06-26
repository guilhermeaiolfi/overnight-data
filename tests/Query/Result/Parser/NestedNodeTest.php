<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query\Result\Parser;

use ON\Data\Query\Result\Parser\CollectionNode;
use ON\Data\Query\Result\Parser\RootNode;
use ON\Data\Query\Result\Parser\SingularNode;
use PHPUnit\Framework\TestCase;

final class NestedNodeTest extends TestCase
{
	public function testJoinedCollectionUnderJoinedCollectionFoldsCartesianRows(): void
	{
		$root = new RootNode(['id', 'name'], ['id']);
		$posts = new CollectionNode(['id', 'user_id', 'title'], ['id'], ['user_id'], ['id']);
		$tags = new CollectionNode(['id', 'post_id', 'label'], ['id'], ['post_id'], ['id']);
		$posts->joinNode('tags', $tags);
		$root->joinNode('posts', $posts);

		foreach ([
			[1, 'Ada', 10, 1, 'First post', 100, 10, 'php'],
			[1, 'Ada', 10, 1, 'First post', 101, 10, 'orm'],
			[1, 'Ada', 11, 1, 'Second post', 102, 11, 'parser'],
		] as $row) {
			$root->parseRow(0, $row);
		}

		self::assertSame([
			[
				'id' => 1,
				'name' => 'Ada',
				'posts' => [
					[
						'id' => 10,
						'user_id' => 1,
						'title' => 'First post',
						'tags' => [
							['id' => 100, 'post_id' => 10, 'label' => 'php'],
							['id' => 101, 'post_id' => 10, 'label' => 'orm'],
						],
					],
					[
						'id' => 11,
						'user_id' => 1,
						'title' => 'Second post',
						'tags' => [
							['id' => 102, 'post_id' => 11, 'label' => 'parser'],
						],
					],
				],
			],
		], $root->getResult());
	}

	public function testCompositeReferencesMountLinkedChildren(): void
	{
		$root = new RootNode(['tenant_id', 'id', 'name'], ['tenant_id', 'id']);
		$root->linkNode('posts', $posts = new CollectionNode(
			['id', 'tenant_id', 'user_id', 'title'],
			['id'],
			['tenant_id', 'user_id'],
			['tenant_id', 'id'],
		));

		foreach ([[10, 1, 'Ada'], [10, 2, 'Linus']] as $row) {
			$root->parseRow(0, $row);
		}

		foreach ([[100, 10, 1, 'First'], [101, 10, 2, 'Second']] as $row) {
			$posts->parseRow(0, $row);
		}

		self::assertSame([
			['tenant_id' => 10, 'id' => 1, 'name' => 'Ada', 'posts' => [['id' => 100, 'tenant_id' => 10, 'user_id' => 1, 'title' => 'First']]],
			['tenant_id' => 10, 'id' => 2, 'name' => 'Linus', 'posts' => [['id' => 101, 'tenant_id' => 10, 'user_id' => 2, 'title' => 'Second']]],
		], $root->getResult());
	}

	public function testLinkedChildUnderJoinedCollectionSupportsJoinedGrandchildren(): void
	{
		$root = new RootNode(['id', 'name'], ['id']);
		$posts = new CollectionNode(['id', 'user_id', 'title'], ['id'], ['user_id'], ['id']);
		$comments = new CollectionNode(['id', 'post_id', 'body'], ['id'], ['post_id'], ['id']);
		$comments->joinNode('author', new SingularNode(['id', 'comment_id', 'name'], ['id'], ['comment_id'], ['id']));
		$posts->linkNode('comments', $comments);
		$root->joinNode('posts', $posts);

		foreach ([[1, 'Ada', 10, 1, 'First'], [1, 'Ada', 11, 1, 'Second']] as $row) {
			$root->parseRow(0, $row);
		}

		foreach ([[100, 10, 'Great', 1, 100, 'Grace'], [101, 10, 'Nice', 2, 101, 'Linus']] as $row) {
			$comments->parseRow(0, $row);
		}

		self::assertSame('Grace', $root->getResult()[0]['posts'][0]['comments'][0]['author']['name']);
		self::assertSame([], $root->getResult()[0]['posts'][1]['comments']);
	}
}
