<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Compiler;

use ON\Data\Definition\Registry;
use ON\Data\ORM\Compiler\SelectQuery\ProjectionCompilation;
use ON\Data\ORM\Compiler\SelectQuery\ProjectionCompiler;
use ON\Data\ORM\State\RepresentationSchema;
use function ON\Data\Query\query;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;
use function ON\Data\Query\x;
use PHPUnit\Framework\TestCase;

final class SelectQueryProjectionCompilerTest extends TestCase
{
	private ProjectionCompiler $compiler;

	protected function setUp(): void
	{
		$this->compiler = new ProjectionCompiler();
	}

	public function testCompilesSelectedRootScalarFields(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query->select($query->name));

		$schema = $this->compiler->compile($query);

		self::assertTrue($schema->hasField('name'));
		self::assertTrue($schema->getField('name')->isWritable());
		self::assertSame('name', $schema->getField('name')->getFieldName());
	}

	public function testUsesAliasAsRepresentationPathWhenSelectedFieldIsAliased(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query->select($query->name->as('display_name')));

		$schema = $this->compiler->compile($query);

		self::assertTrue($schema->hasField('display_name'));
		self::assertFalse($schema->hasField('name'));
		self::assertSame('name', $schema->getField('display_name')->getFieldName());
	}

	public function testIncludesMissingPrimaryKeyFieldsAsReadOnly(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query->select($query->name));

		$schema = $this->compiler->compile($query);

		self::assertTrue($schema->hasField('id'));
		self::assertTrue($schema->getField('id')->isReadOnly());
		self::assertTrue($schema->getField('name')->isWritable());
	}

	public function testSupportsCompositePrimaryKeys(): void
	{
		$registry = $this->makeCompositePrimaryKeyRegistry();
		$memberships = $registry->getCollection('memberships');
		$query = query($memberships, fn (SelectQuery $query) => $query->select($query->role));

		$schema = $this->compiler->compile($query);

		self::assertTrue($schema->hasField('tenant_id'));
		self::assertTrue($schema->hasField('user_id'));
		self::assertTrue($schema->getField('tenant_id')->isReadOnly());
		self::assertTrue($schema->getField('user_id')->isReadOnly());
		self::assertTrue($schema->getField('role')->isWritable());
	}

	public function testFallsBackToAllCollectionFieldsWhenRootQueryHasNoExplicitScalarSelection(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query->posts->load());

		$schema = $this->compiler->compile($query);

		self::assertTrue($schema->hasField('id'));
		self::assertTrue($schema->hasField('name'));
		self::assertTrue($schema->getField('id')->isWritable());
		self::assertTrue($schema->getField('name')->isWritable());
	}

	public function testCompilesRelationSourcedFlatFieldToRelatedCollection(): void
	{
		$registry = $this->makeRegistryWithCompany();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$query = query($users, fn (SelectQuery $query) => $query
			->select($query->id, $query->company->name->as('name')));

		$compilation = $this->compiler->compileResult($query);
		$schema = $compilation->getSchema();

		self::assertTrue($schema->hasField('id'));
		self::assertTrue($schema->hasField('name'));
		self::assertSame('users', $schema->getField('id')->getCollectionName());
		self::assertSame('companies', $schema->getField('name')->getCollectionName());
		self::assertSame('name', $schema->getField('name')->getFieldName());
		self::assertTrue($schema->getField('id')->isRootSource());
		self::assertSame(['company'], $schema->getField('name')->getSourcePath());

		$internalSelections = $query->getSelections()->getByTag(SelectionTag::INTERNAL);
		self::assertCount(1, $internalSelections);
		self::assertTrue($internalSelections[0]->hasTag(SelectionTag::INTERNAL));

		$identityColumns = $compilation->getIdentityColumns();
		self::assertNotNull($identityColumns->get(['company'], 'id'));
		self::assertSame(
			$internalSelections[0]->getSelectionKey(),
			$identityColumns->get(['company'], 'id'),
		);
	}

	public function testCompileSchemaReturnsRepresentationSchemaWithoutInternalSelections(): void
	{
		$registry = $this->makeRegistryWithCompany();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query
			->select($query->id, $query->company->name->as('name')));

		$schema = $this->compiler->compileSchema($query);

		self::assertInstanceOf(RepresentationSchema::class, $schema);
		self::assertTrue($schema->hasField('id'));
		self::assertTrue($schema->hasField('name'));
		self::assertSame(['company'], $schema->getField('name')->getSourcePath());
		self::assertCount(0, $query->getSelections()->getByTag(SelectionTag::INTERNAL));
	}

	public function testCompileSchemaBehavesLikeCompileNotCompileResult(): void
	{
		$registry = $this->makeRegistryWithCompany();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query
			->select($query->id, $query->company->name->as('name')));

		$schema = $this->compiler->compile($query);

		self::assertInstanceOf(RepresentationSchema::class, $schema);
		self::assertCount(0, $query->getSelections()->getByTag(SelectionTag::INTERNAL));
	}

	public function testCompileResultAddsInternalIdentitySelectionsWhenSchemaWouldNot(): void
	{
		$registry = $this->makeRegistryWithCompany();
		$users = $registry->getCollection('users');

		$schemaQuery = query($users, fn (SelectQuery $query) => $query
			->select($query->id, $query->company->name->as('name')));
		$this->compiler->compileSchema($schemaQuery);
		self::assertCount(0, $schemaQuery->getSelections()->getByTag(SelectionTag::INTERNAL));

		$resultQuery = query($users, fn (SelectQuery $query) => $query
			->select($query->id, $query->company->name->as('name')));
		$compilation = $this->compiler->compileResult($resultQuery);

		self::assertCount(1, $resultQuery->getSelections()->getByTag(SelectionTag::INTERNAL));
		self::assertNotNull($compilation->getIdentityColumns()->get(['company'], 'id'));
	}

	public function testCompileResultReturnsProjectionCompilationWithSourcePathKeyedIdentities(): void
	{
		$registry = $this->makeRegistryWithCompany();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query
			->select($query->id, $query->company->name->as('name')));

		$compilation = $this->compiler->compileResult($query);

		self::assertInstanceOf(ProjectionCompilation::class, $compilation);
		self::assertTrue($compilation->getSchema()->getField('name')->getSourcePath() === ['company']);
		self::assertNotNull($compilation->getIdentityColumns()->get(['company'], 'id'));
		self::assertNull($compilation->getIdentityColumns()->get([], 'id'));
	}

	public function testCompileResultCarriesStructuralProjectionSources(): void
	{
		$registry = $this->makeRegistryWithCompany();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query
			->select(
				$query->id,
				$query->company->name->as('companyName'),
				$query->company->id->as('companyId'),
			));

		$compilation = $this->compiler->compileResult($query);
		$sources = $compilation->getSources();

		self::assertCount(2, $sources);
		self::assertTrue($compilation->hasNonRootSources());
		self::assertSame([], $sources[0]->getPath());
		self::assertSame(['company'], $sources[1]->getPath());
		self::assertSame('companies', $sources[1]->getCollection()->getName());
		self::assertTrue($sources[1]->hasField('name'));
		self::assertTrue($sources[1]->hasField('id'));
		self::assertFalse($sources[1]->hasField('companyName'));
		self::assertSame('companyName', $sources[1]->getFieldPath('name'));
		self::assertSame('companyId', $sources[1]->getFieldPath('id'));
	}

	public function testComputedExpressionsDoNotCreateProjectionSourceFields(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query->select($query->name, $query->id->count()->as('post_count')));

		$compilation = $this->compiler->compileResult($query);

		self::assertFalse($compilation->getSchema()->hasField('post_count'));
		self::assertCount(1, $compilation->getSources());
		self::assertNull($compilation->getSources()[0]->getFieldPath('post_count'));
	}

	public function testSameTerminalCollectionFlatProjectionKeepsDistinctSourcePaths(): void
	{
		$registry = $this->makeRegistryWithManager();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query
			->select($query->name, $query->manager->name->as('managerName')));

		$compilation = $this->compiler->compileResult($query);
		$schema = $compilation->getSchema();

		self::assertSame('users', $schema->getField('name')->getCollectionName());
		self::assertSame('users', $schema->getField('managerName')->getCollectionName());
		self::assertSame([], $schema->getField('name')->getSourcePath());
		self::assertSame(['manager'], $schema->getField('managerName')->getSourcePath());

		$identities = $compilation->getIdentityColumns();
		self::assertNull($identities->get([], 'id'));
		self::assertNotNull($identities->get(['manager'], 'id'));

		$internal = $query->getSelections()->getByTag(SelectionTag::INTERNAL);
		self::assertCount(1, $internal);
		self::assertSame($internal[0]->getSelectionKey(), $identities->get(['manager'], 'id'));
	}

	public function testInternalIdentityFieldPathsDoNotCollideWithVisibleAliases(): void
	{
		$registry = $this->makeRegistryWithCompany();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query
			->select($query->company->name->as('company_name')));

		$compilation = $this->compiler->compileResult($query);
		$schema = $compilation->getSchema();

		self::assertTrue($schema->hasField('company_name'));
		self::assertFalse($schema->hasField('company.id'));
		self::assertNotNull($compilation->getIdentityColumns()->get(['company'], 'id'));
	}

	public function testCompilesSelectedRootRelation(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query
			->select($query->name)
			->posts
			->fields('title'));

		$schema = $this->compiler->compile($query);
		$posts = $schema->getRelation('posts');

		self::assertSame('posts', $posts->getPath());
		self::assertSame('users', $posts->getOwnerCollectionName());
		self::assertSame('posts', $posts->getRelationName());
		self::assertTrue($posts->isMany());
		self::assertTrue($posts->getRelatedSchema()->hasField('title'));
		self::assertTrue($posts->getRelatedSchema()->hasField('id'));
		self::assertTrue($posts->getRelatedSchema()->getField('id')->isReadOnly());
	}

	public function testRelationWithLoadAndNoFieldsFallsBackToTargetDefinitionFields(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query
			->select($query->name)
			->posts
			->load());

		$schema = $this->compiler->compile($query);
		$posts = $schema->getRelation('posts')->getRelatedSchema();

		self::assertTrue($posts->hasField('id'));
		self::assertTrue($posts->hasField('title'));
		self::assertTrue($posts->hasField('user_id'));
		self::assertTrue($posts->getField('title')->isWritable());
	}

	public function testRelationLoadCompilesStructuralToManyRelation(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query
			->select($query->name)
			->posts
			->load());

		$schema = $this->compiler->compile($query);

		self::assertTrue($schema->getRelation('posts')->isMany());
	}

	public function testRelationWithLimitStillCompilesStructuralRelation(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query
			->select($query->name)
			->posts
			->load()
			->limit(5));

		$schema = $this->compiler->compile($query);

		self::assertTrue($schema->getRelation('posts')->isMany());
	}

	public function testRelationWithOffsetStillCompilesStructuralRelation(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query
			->select($query->name)
			->posts
			->fields('title')
			->offset(10));

		$schema = $this->compiler->compile($query);

		self::assertTrue($schema->getRelation('posts')->isMany());
	}

	public function testRelationWithConditionsStillCompilesStructuralRelation(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, function (SelectQuery $query): void {
			$query->select($query->name);
			$query->posts->fields('title')->where(x()->eq($query->posts->title, 'Hello'));
		});

		$schema = $this->compiler->compile($query);

		self::assertTrue($schema->getRelation('posts')->isMany());
	}

	public function testToOneRelationDerivesSingleCardinalityFromDefinition(): void
	{
		$registry = $this->makeRegistryWithProfile();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query
			->select($query->name)
			->profile
			->load());

		$schema = $this->compiler->compile($query);
		$profile = $schema->getRelation('profile');

		self::assertTrue($profile->isSingle());
	}

	public function testNestedRelationCompilesIntoNestedRelatedSchemaNotFlattenedRootPaths(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query
			->select($query->name)
			->posts
			->comments
			->fields('body'));

		$schema = $this->compiler->compile($query);

		self::assertTrue($schema->hasRelation('posts'));
		self::assertFalse($schema->hasRelation('posts.comments'));

		$postsSchema = $schema->getRelation('posts')->getRelatedSchema();
		self::assertTrue($postsSchema->hasRelation('comments'));
		self::assertFalse($postsSchema->hasRelation('posts.comments'));

		$commentsSchema = $postsSchema->getRelation('comments')->getRelatedSchema();
		self::assertTrue($commentsSchema->hasField('body'));
		self::assertTrue($commentsSchema->hasField('id'));
		self::assertTrue($commentsSchema->getField('id')->isReadOnly());
	}

	public function testIgnoresUnsupportedExpressions(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query->select($query->name, $query->id->count()->as('post_count')));

		$schema = $this->compiler->compile($query);

		self::assertTrue($schema->hasField('name'));
		self::assertFalse($schema->hasField('post_count'));
	}

	public function testExpressionOnlyRootSelectionDoesNotFallbackToDefaultFields(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query->select($query->id->count()->as('total')));

		$schema = $this->compiler->compile($query);

		self::assertTrue($schema->hasField('id'));
		self::assertTrue($schema->getField('id')->isReadOnly());

		self::assertFalse($schema->hasField('name'));
	}

	public function testShapeBasedRootAndFlatFieldsPreserveWritabilityAndSourcePaths(): void
	{
		$registry = $this->makeRegistryWithManager();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query
			->select($query->name, $query->manager->name->as('managerName')));

		$schema = $this->compiler->compileSchema($query);

		self::assertTrue($schema->getField('name')->isWritable());
		self::assertSame([], $schema->getField('name')->getSourcePath());
		self::assertSame('users', $schema->getField('name')->getCollectionName());

		self::assertTrue($schema->getField('managerName')->isWritable());
		self::assertSame(['manager'], $schema->getField('managerName')->getSourcePath());
		self::assertSame('users', $schema->getField('managerName')->getCollectionName());
		self::assertSame('name', $schema->getField('managerName')->getFieldName());

		self::assertTrue($schema->getField('id')->isReadOnly());
		self::assertSame([], $schema->getField('id')->getSourcePath());
	}

	public function testNestedDefaultRelationFieldsKeepPrimaryKeyWritable(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query
			->select($query->name)
			->posts
			->load());

		$schema = $this->compiler->compile($query);
		$posts = $schema->getRelation('posts')->getRelatedSchema();

		self::assertTrue($posts->getField('id')->isWritable());
		self::assertSame([], $posts->getField('id')->getSourcePath());
		self::assertSame('posts', $posts->getField('id')->getCollectionName());
	}

	public function testNestedExplicitRelationFieldsAddPrimaryKeyAsReadOnly(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = query($users, fn (SelectQuery $query) => $query
			->select($query->name)
			->posts
			->fields('title'));

		$schema = $this->compiler->compile($query);
		$posts = $schema->getRelation('posts')->getRelatedSchema();

		self::assertTrue($posts->getField('title')->isWritable());
		self::assertTrue($posts->getField('id')->isReadOnly());
		self::assertSame([], $posts->getField('title')->getSourcePath());
		self::assertSame([], $posts->getField('id')->getSourcePath());
	}

	public function testReturnsNewRepresentationSchemaEachTime(): void
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

	private function makeRegistryWithManager(): Registry
	{
		$registry = $this->makeRegistry();

		$users = $registry->getCollection('users');
		$users->field('manager_id', 'int')->end();
		$users->belongsTo('manager', 'users')->innerKey('manager_id')->outerKey('id');

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
