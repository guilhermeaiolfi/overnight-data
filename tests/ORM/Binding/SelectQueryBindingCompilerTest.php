<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Binding;

use ON\Data\Definition\Registry;
use ON\Data\ORM\Binding\SelectQueryBindingCompiler;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use function ON\Data\Query\query;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;
use function ON\Data\Query\x;
use PHPUnit\Framework\TestCase;

final class SelectQueryBindingCompilerTest extends TestCase
{
	private SelectQueryBindingCompiler $compiler;

	protected function setUp(): void
	{
		$this->compiler = new SelectQueryBindingCompiler();
	}

	public function testCompilesSelectedRootScalarFields(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query->select($query->name));

		$binding = $this->compiler->compile($query);

		self::assertTrue($binding->hasField('name'));
		self::assertTrue($binding->getField('name')->isWritable());
		self::assertSame('name', $binding->getField('name')->getField()->getFieldName());
	}

	public function testUsesAliasAsRepresentationPathWhenSelectedFieldIsAliased(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query->select($query->name->as('display_name')));

		$binding = $this->compiler->compile($query);

		self::assertTrue($binding->hasField('display_name'));
		self::assertFalse($binding->hasField('name'));
		self::assertSame('name', $binding->getField('display_name')->getField()->getFieldName());
	}

	public function testIncludesMissingPrimaryKeyFieldsAsReadOnly(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query->select($query->name));

		$binding = $this->compiler->compile($query);

		self::assertTrue($binding->hasField('id'));
		self::assertTrue($binding->getField('id')->isReadOnly());
		self::assertTrue($binding->getField('name')->isWritable());
	}

	public function testSupportsCompositePrimaryKeys(): void
	{
		$registry = $this->makeCompositePrimaryKeyRegistry();
		$memberships = $registry->getCollection('memberships');
		$query = query($memberships, fn (SelectQuery $query) => $query->select($query->role));

		$binding = $this->compiler->compile($query);

		self::assertTrue($binding->hasField('tenant_id'));
		self::assertTrue($binding->hasField('user_id'));
		self::assertTrue($binding->getField('tenant_id')->isReadOnly());
		self::assertTrue($binding->getField('user_id')->isReadOnly());
		self::assertTrue($binding->getField('role')->isWritable());
	}

	public function testFallsBackToAllCollectionFieldsWhenRootQueryHasNoExplicitScalarSelection(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query->posts->load());

		$binding = $this->compiler->compile($query);

		self::assertTrue($binding->hasField('id'));
		self::assertTrue($binding->hasField('name'));
		self::assertTrue($binding->getField('id')->isWritable());
		self::assertTrue($binding->getField('name')->isWritable());
	}

	public function testCompilesRelationSourcedFlatFieldToRelatedCollection(): void
	{
		$registry = $this->makeRegistryWithCompany();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$query = query($users, fn (SelectQuery $query) => $query
			->select($query->id, $query->company->name->as('name')));

		$compilation = $this->compiler->compileResult($query);
		$binding = $compilation->getBinding();

		self::assertTrue($binding->hasField('id'));
		self::assertTrue($binding->hasField('name'));
		self::assertSame('users', $binding->getField('id')->getField()->getCollectionName());
		self::assertSame('companies', $binding->getField('name')->getField()->getCollectionName());
		self::assertSame('name', $binding->getField('name')->getField()->getFieldName());

		$internalSelections = $query->getSelections()->getByTag(SelectionTag::INTERNAL_RESULT);
		self::assertCount(1, $internalSelections);
		self::assertTrue($internalSelections[0]->hasTag(SelectionTag::INTERNAL));
		self::assertStringStartsWith('_od_internal_', $internalSelections[0]->getSelectionKey());
		self::assertStringNotContainsString('__od.', $internalSelections[0]->getSelectionKey());

		self::assertSame(
			$internalSelections[0]->getSelectionKey(),
			$compilation->getProjectionIdentities()->get($companies, 'id'),
		);
	}

	public function testInternalIdentityFieldPathsDoNotCollideWithVisibleAliases(): void
	{
		$registry = $this->makeRegistryWithCompany();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query
			->select($query->company->name->as('company_name')));

		$compilation = $this->compiler->compileResult($query);
		$binding = $compilation->getBinding();

		self::assertTrue($binding->hasField('company_name'));
		self::assertFalse($binding->hasField('company.id'));
		self::assertNotNull($compilation->getProjectionIdentities()->get($registry->getCollection('companies'), 'id'));
	}

	public function testCompilesSelectedRootRelation(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query
			->select($query->name)
			->posts
			->fields('title'));

		$binding = $this->compiler->compile($query);
		$posts = $binding->getRelation('posts');

		self::assertSame('posts', $posts->getPath());
		self::assertSame('users', $posts->getRelation()->getCollectionName());
		self::assertSame('posts', $posts->getRelation()->getRelationName());
		self::assertSame(RepresentationRelationCardinality::MANY, $posts->getCardinality());
		self::assertTrue($posts->isCollectionFullyLoaded());
		self::assertTrue($posts->getRelatedBinding()->hasField('title'));
		self::assertTrue($posts->getRelatedBinding()->hasField('id'));
		self::assertTrue($posts->getRelatedBinding()->getField('id')->isReadOnly());
	}

	public function testRelationWithLoadAndNoFieldsFallsBackToTargetDefinitionFields(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query
			->select($query->name)
			->posts
			->load());

		$binding = $this->compiler->compile($query);
		$posts = $binding->getRelation('posts')->getRelatedBinding();

		self::assertTrue($posts->hasField('id'));
		self::assertTrue($posts->hasField('title'));
		self::assertTrue($posts->hasField('user_id'));
		self::assertTrue($posts->getField('title')->isWritable());
	}

	public function testRelationLoadWithoutLimitIsCollectionFullyLoadedForToManyRelations(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query
			->select($query->name)
			->posts
			->load());

		$binding = $this->compiler->compile($query);

		self::assertTrue($binding->getRelation('posts')->isCollectionFullyLoaded());
	}

	public function testRelationWithLimitIsNotCollectionFullyLoaded(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query
			->select($query->name)
			->posts
			->load()
			->limit(5));

		$binding = $this->compiler->compile($query);

		self::assertFalse($binding->getRelation('posts')->isCollectionFullyLoaded());
	}

	public function testRelationWithOffsetIsNotCollectionFullyLoaded(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query
			->select($query->name)
			->posts
			->fields('title')
			->offset(10));

		$binding = $this->compiler->compile($query);

		self::assertFalse($binding->getRelation('posts')->isCollectionFullyLoaded());
	}

	public function testRelationWithConditionsIsNotCollectionFullyLoaded(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, function (SelectQuery $query): void {
			$query->select($query->name);
			$query->posts->fields('title')->where(x()->eq($query->posts->title, 'Hello'));
		});

		$binding = $this->compiler->compile($query);

		self::assertFalse($binding->getRelation('posts')->isCollectionFullyLoaded());
	}

	public function testToOneRelationDoesNotUseCollectionFullyLoadedSemantics(): void
	{
		$registry = $this->makeRegistryWithProfile();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query
			->select($query->name)
			->profile
			->load());

		$binding = $this->compiler->compile($query);
		$profile = $binding->getRelation('profile');

		self::assertSame(RepresentationRelationCardinality::ONE, $profile->getCardinality());
		self::assertFalse($profile->isCollectionFullyLoaded());
	}

	public function testNestedRelationCompilesIntoNestedRelatedBindingNotFlattenedRootPaths(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query
			->select($query->name)
			->posts
			->comments
			->fields('body'));

		$binding = $this->compiler->compile($query);

		self::assertTrue($binding->hasRelation('posts'));
		self::assertFalse($binding->hasRelation('posts.comments'));

		$postsBinding = $binding->getRelation('posts')->getRelatedBinding();
		self::assertTrue($postsBinding->hasRelation('comments'));
		self::assertFalse($postsBinding->hasRelation('posts.comments'));

		$commentsBinding = $postsBinding->getRelation('comments')->getRelatedBinding();
		self::assertTrue($commentsBinding->hasField('body'));
		self::assertTrue($commentsBinding->hasField('id'));
		self::assertTrue($commentsBinding->getField('id')->isReadOnly());
	}

	public function testIgnoresUnsupportedExpressions(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query->select($query->name, $query->id->count()->as('post_count')));

		$binding = $this->compiler->compile($query);

		self::assertTrue($binding->hasField('name'));
		self::assertFalse($binding->hasField('post_count'));
		self::assertSame([], $binding->getExpressions());
	}

	public function testExpressionOnlyRootSelectionDoesNotFallbackToDefaultFields(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query->select($query->id->count()->as('total')));

		$binding = $this->compiler->compile($query);

		self::assertTrue($binding->hasField('id'));
		self::assertTrue($binding->getField('id')->isReadOnly());

		self::assertFalse($binding->hasField('name'));
		self::assertSame([], $binding->getExpressions());
	}

	public function testReturnsNewRepresentationBindingEachTime(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query->select($query->name));

		$first = $this->compiler->compile($query);
		$second = $this->compiler->compile($query);

		self::assertNotSame($first, $second);
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();

		$registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		$registry->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end()
			->field('user_id', 'int')->end();

		$registry->collection('comments')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('body', 'string')->end()
			->field('post_id', 'int')->end();

		$users = $registry->getCollection('users');
		$users->hasMany('posts', 'posts')->innerKey('id')->outerKey('user_id');

		$posts = $registry->getCollection('posts');
		$posts->hasMany('comments', 'comments')->innerKey('id')->outerKey('post_id');

		return $registry;
	}

	private function makeCompositePrimaryKeyRegistry(): Registry
	{
		$registry = new Registry();

		$registry->collection('memberships')
			->primaryKey('tenant_id', 'user_id')
			->field('tenant_id', 'int')->end()
			->field('user_id', 'int')->end()
			->field('role', 'string')->end();

		return $registry;
	}

	private function makeRegistryWithProfile(): Registry
	{
		$registry = $this->makeRegistry();

		$registry->collection('profiles')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('label', 'string')->end()
			->field('user_id', 'int')->end();

		$users = $registry->getCollection('users');
		$users->hasOne('profile', 'profiles')->innerKey('id')->outerKey('user_id');

		return $registry;
	}

	private function makeRegistryWithCompany(): Registry
	{
		$registry = $this->makeRegistry();

		$registry->collection('companies')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		$users = $registry->getCollection('users');
		$users->field('company_id', 'int')->end();
		$users->belongsTo('company', 'companies')->innerKey('company_id')->outerKey('id');

		return $registry;
	}
}
