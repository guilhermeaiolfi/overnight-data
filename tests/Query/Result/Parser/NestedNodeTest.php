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
		$posts = new CollectionNode(
			['id' => 'posts.id', 'user_id' => 'posts.user_id', 'title' => 'posts.title'],
			['id'],
			['user_id'],
			['id'],
		);
		$tags = new CollectionNode(
			['id' => 'tags.id', 'post_id' => 'tags.post_id', 'label' => 'tags.label'],
			['id'],
			['post_id'],
			['id'],
		);
		$posts->joinNode('tags', $tags);
		$root->joinNode('posts', $posts);

		foreach ([
			[
				'id' => 1,
				'name' => 'Ada',
				'posts.id' => 10,
				'posts.user_id' => 1,
				'posts.title' => 'First post',
				'tags.id' => 100,
				'tags.post_id' => 10,
				'tags.label' => 'php',
			],
			[
				'id' => 1,
				'name' => 'Ada',
				'posts.id' => 10,
				'posts.user_id' => 1,
				'posts.title' => 'First post',
				'tags.id' => 101,
				'tags.post_id' => 10,
				'tags.label' => 'orm',
			],
			[
				'id' => 1,
				'name' => 'Ada',
				'posts.id' => 11,
				'posts.user_id' => 1,
				'posts.title' => 'Second post',
				'tags.id' => 102,
				'tags.post_id' => 11,
				'tags.label' => 'parser',
			],
		] as $row) {
			$root->parseRow($row);
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

		foreach ([
			['tenant_id' => 10, 'id' => 1, 'name' => 'Ada'],
			['tenant_id' => 10, 'id' => 2, 'name' => 'Linus'],
		] as $row) {
			$root->parseRow($row);
		}

		foreach ([
			['id' => 100, 'tenant_id' => 10, 'user_id' => 1, 'title' => 'First'],
			['id' => 101, 'tenant_id' => 10, 'user_id' => 2, 'title' => 'Second'],
		] as $row) {
			$posts->parseRow($row);
		}

		self::assertSame([
			['tenant_id' => 10, 'id' => 1, 'name' => 'Ada', 'posts' => [['id' => 100, 'tenant_id' => 10, 'user_id' => 1, 'title' => 'First']]],
			['tenant_id' => 10, 'id' => 2, 'name' => 'Linus', 'posts' => [['id' => 101, 'tenant_id' => 10, 'user_id' => 2, 'title' => 'Second']]],
		], $root->getResult());
	}

	public function testLinkedChildUnderJoinedCollectionSupportsJoinedGrandchildren(): void
	{
		$root = new RootNode(['id', 'name'], ['id']);
		$posts = new CollectionNode(
			['id' => 'posts.id', 'user_id' => 'posts.user_id', 'title' => 'posts.title'],
			['id'],
			['user_id'],
			['id'],
		);
		$comments = new CollectionNode(['id', 'post_id', 'body'], ['id'], ['post_id'], ['id']);
		$comments->joinNode('author', new SingularNode(
			['id' => 'author.id', 'comment_id' => 'author.comment_id', 'name' => 'author.name'],
			['id'],
			['comment_id'],
			['id'],
		));
		$posts->linkNode('comments', $comments);
		$root->joinNode('posts', $posts);

		foreach ([
			['id' => 1, 'name' => 'Ada', 'posts.id' => 10, 'posts.user_id' => 1, 'posts.title' => 'First'],
			['id' => 1, 'name' => 'Ada', 'posts.id' => 11, 'posts.user_id' => 1, 'posts.title' => 'Second'],
		] as $row) {
			$root->parseRow($row);
		}

		foreach ([
			['id' => 100, 'post_id' => 10, 'body' => 'Great', 'author.id' => 1, 'author.comment_id' => 100, 'author.name' => 'Grace'],
			['id' => 101, 'post_id' => 10, 'body' => 'Nice', 'author.id' => 2, 'author.comment_id' => 101, 'author.name' => 'Linus'],
		] as $row) {
			$comments->parseRow($row);
		}

		self::assertSame('Grace', $root->getResult()[0]['posts'][0]['comments'][0]['author']['name']);
		self::assertSame([], $root->getResult()[0]['posts'][1]['comments']);
	}
}
