<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query\Result\Parser;

use ON\Data\Query\Result\Parser\CollectionNode;
use ON\Data\Query\Result\Parser\ParserException;
use ON\Data\Query\Result\Parser\RootNode;
use PHPUnit\Framework\TestCase;

final class OwnershipTest extends TestCase
{
	public function testNodeCannotBeAttachedToMultipleParents(): void
	{
		$this->expectException(ParserException::class);

		$posts = new CollectionNode(['id', 'user_id', 'title'], ['id'], ['user_id'], ['id']);
		(new RootNode(['id'], ['id']))->joinNode('posts', $posts);
		(new RootNode(['id'], ['id']))->joinNode('posts', $posts);
	}

	public function testDuplicateContainerNamesAreRejected(): void
	{
		$this->expectException(ParserException::class);

		$root = new RootNode(['id'], ['id']);
		$root->joinNode('posts', new CollectionNode(['id', 'user_id', 'title'], ['id'], ['user_id'], ['id']));
		$root->joinNode('posts', new CollectionNode(['id', 'user_id', 'title'], ['id'], ['user_id'], ['id']));
	}

	public function testNodeCannotAttachToItself(): void
	{
		$this->expectException(ParserException::class);

		$root = new RootNode(['id'], ['id']);
		$root->joinNode('self', $root);
	}

	public function testSimpleAncestorCycleIsRejected(): void
	{
		$this->expectException(ParserException::class);

		$root = new RootNode(['id'], ['id']);
		$posts = new CollectionNode(['id', 'user_id'], ['id'], ['user_id'], ['id']);
		$root->joinNode('posts', $posts);
		$posts->joinNode('root', $root);
	}
}
