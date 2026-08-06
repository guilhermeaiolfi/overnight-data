<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query\Load;

use ON\Data\Definition\Registry;
use ON\Data\Query\Load\LoadGraphBuilder;
use ON\Data\Query\Relation\Loader\BelongsToLoader;
use ON\Data\Query\Relation\Loader\HasManyLoader;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;

final class LoadGraphBuilderTest extends TestCase
{
	public function testRootAndNestedOwnFieldsGroupBySourcePath(): void
	{
		$users = new SelectQuery($this->makeRegistry()->getCollection('users'));
		$users->select($users->id, $users->name);
		$users->posts->select('id', 'title')->separate();

		$graph = (new LoadGraphBuilder())->fromQuery($users);

		$root = $graph->get([]);
		self::assertNotNull($root);
		self::assertSame(['id', 'name'], $root->getFields());
		self::assertTrue($root->isLoaded());

		$posts = $graph->get(['posts']);
		self::assertNotNull($posts);
		self::assertSame(['id', 'title'], $posts->getFields());
		self::assertTrue($posts->isLoaded());
		self::assertSame(LoadStrategy::SEPARATE_QUERY, $posts->getStrategy());
		self::assertFalse($posts->usesDefaultFields());
	}

	public function testFlatRelatedFieldLandsOnSourceNodeNotOnlyParent(): void
	{
		$users = new SelectQuery($this->makeRegistry()->getCollection('users'));
		$users->posts
			->select(
				$users->posts->id,
				$users->posts->author->name->as('authorName'),
			)
			->separate();

		$graph = (new LoadGraphBuilder())->fromQuery($users);

		$posts = $graph->get(['posts']);
		self::assertNotNull($posts);
		self::assertSame(['id'], $posts->getFields());
		self::assertNotContains('authorName', $posts->getFields());

		$author = $graph->get(['posts', 'author']);
		self::assertNotNull($author);
		self::assertSame(['name'], $author->getFields());
		self::assertSame('authors', $author->getCollection()->getName());
		self::assertFalse($author->isLoaded());
	}

	public function testDefaultRelationSelectionMarksDefaultFields(): void
	{
		$users = new SelectQuery($this->makeRegistry()->getCollection('users'));
		$users->posts->load()->separate();

		$graph = (new LoadGraphBuilder())->fromQuery($users);
		$posts = $graph->get(['posts']);

		self::assertNotNull($posts);
		self::assertTrue($posts->usesDefaultFields());
		self::assertSame([], $posts->getFields());
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();

		$authors = $registry->collection('authors');
		$authors->field('id', 'int');
		$authors->field('name', 'string');
		$authors->primaryKey('id');

		$posts = $registry->collection('posts');
		$posts->field('id', 'int');
		$posts->field('userId', 'int');
		$posts->field('authorId', 'int');
		$posts->field('title', 'string');
		$posts->primaryKey('id');
		$posts->belongsTo('author', 'authors')
			->innerKey('authorId')
			->outerKey('id')
			->loader(BelongsToLoader::class)
			->end();

		$users = $registry->collection('users');
		$users->field('id', 'int');
		$users->field('name', 'string');
		$users->primaryKey('id');
		$users->hasMany('posts', 'posts')
			->innerKey('id')
			->outerKey('userId')
			->loader(HasManyLoader::class)
			->end();

		return $registry;
	}
}
