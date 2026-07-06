<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Binding;

use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\ORM\Binding\ProjectionBindingAssembler;
use ON\Data\ORM\Binding\ProjectionSelectionNormalizer;
use ON\Data\ORM\Binding\QueryProjectionSourceResolver;
use ON\Data\ORM\ManualProjection\ManualProjectionBuilder;
use ON\Data\ORM\ManualProjection\ManualProjectionRelationApplier;
use ON\Data\ORM\ManualProjection\ManualProjectionRepresentationTracker;
use ON\Data\ORM\ManualProjection\ManualProjectionSourceResolver;
use ON\Data\ORM\ManualProjection\ManualProjectionTarget;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Session;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStore;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use stdClass;
use Tests\ON\Data\Smoke\Support\SqliteMemoryHarness;

final class ProjectionCompilationArchitectureTest extends TestCase
{
	public function testRemovedProjectionIdentityProviderApiDoesNotExist(): void
	{
		$root = dirname(__DIR__, 3);

		self::assertFileDoesNotExist($root . '/src/ORM/Binding/ProjectionIdentityProviderInterface.php');
		self::assertFileDoesNotExist($root . '/src/ORM/Binding/SelectQueryProjectionIdentityProvider.php');
		self::assertFileDoesNotExist($root . '/src/ORM/ManualProjection/ManualProjectionIdentityProvider.php');
		self::assertFileDoesNotExist($root . '/src/ORM/Binding/SelectionProjectionCompiler.php');
		self::assertFalse(method_exists(ProjectionSelectionNormalizer::class, 'fieldForSelection'));
		self::assertFalse(method_exists(ManualProjectionSourceResolver::class, 'fieldForSelection'));
	}

	public function testManualBuilderUsesSharedSelectionNormalizerAndBindingAssembler(): void
	{
		$constructor = (new ReflectionClass(ManualProjectionBuilder::class))->getConstructor();
		self::assertNotNull($constructor);

		$parameters = $constructor->getParameters();

		self::assertSame(ProjectionSelectionNormalizer::class, $parameters[2]->getType()?->getName());
		self::assertSame(ProjectionBindingAssembler::class, $parameters[3]->getType()?->getName());
		self::assertSame(ManualProjectionSourceResolver::class, $parameters[4]->getType()?->getName());
	}

	public function testManualBuilderDoesNotOwnExtractedProjectionInternals(): void
	{
		$contents = (string) file_get_contents(dirname(__DIR__, 3) . '/src/ORM/ManualProjection/ManualProjectionBuilder.php');

		self::assertStringNotContainsString('RecordFieldRef', $contents);
		self::assertStringNotContainsString('RepresentationFieldBinding', $contents);
		self::assertStringNotContainsString('new ToManyRelationState', $contents);
		self::assertStringNotContainsString('new ToOneRelationState', $contents);
		self::assertStringNotContainsString('relationBindingFromPath', $contents);
		self::assertStringNotContainsString('mergeBindings', $contents);
		self::assertStringNotContainsString('mirrorRelationTarget', $contents);
	}

	public function testProjectionPathSourceWasReplacedByManualProjectionTarget(): void
	{
		$root = dirname(__DIR__, 3);

		self::assertFileDoesNotExist($root . '/src/ORM/ManualProjection/ProjectionPathSource.php');
		self::assertFileExists($root . '/src/ORM/ManualProjection/ManualProjectionTarget.php');
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testObjectShapedFromPathCreateDoesNotInstantiateSelectQueryUntilFieldAccess(): void
	{
		[$session, $user] = $this->trackedUserWithPostsRelation();
		$post = new stdClass();
		$post->title = 'Direct branch';

		$target = $session
			->projection($post)
			->fromPath($user, 'posts')
			->create();

		self::assertInstanceOf(ManualProjectionTarget::class, $target);
		self::assertNull($this->selectionSourceFromTarget($target));
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testObjectShapedFromPathTracksActualPostObjectAsRelationTarget(): void
	{
		[$session, $user] = $this->trackedUserWithPostsRelation();
		$post = new stdClass();
		$post->title = 'Tracked post';

		$session
			->projection($post)
			->fromPath($user, 'posts')
			->create()
			->end();

		$userRecord = $session->getRecords()->getFromRepresentation($session->getRepresentations()->get($user));
		$relation = $session->getToManyRelations()->get($userRecord, 'posts');

		self::assertInstanceOf(ToManyRelationState::class, $relation);
		self::assertSame([$post], $relation->getAdded());
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testObjectShapedFromPathDoesNotMutateOwnerPostsProperty(): void
	{
		[$session, $user] = $this->trackedUserWithPostsRelation();
		$postsBefore = $user->posts ?? null;
		$post = new stdClass();
		$post->title = 'No mirror';

		$session
			->projection($post)
			->fromPath($user, 'posts')
			->create()
			->end();

		self::assertSame($postsBefore, $user->posts ?? null);
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testObjectShapedFromPathAppliesRelatedBindingBranchDirectly(): void
	{
		[$session, $user] = $this->trackedUserWithPostsRelation();
		$userBinding = $session->getRepresentations()->get($user)->getBinding();
		$relatedBinding = $userBinding->getRelation('posts')->getRelatedBinding();
		$post = new stdClass();
		$post->title = 'Branch reuse';

		$session
			->projection($post)
			->fromPath($user, 'posts')
			->create()
			->end();

		$postState = $session->getRepresentations()->get($post);
		self::assertInstanceOf(RepresentationState::class, $postState);
		self::assertSame($relatedBinding->getPaths(), $postState->getBinding()->getPaths());
		self::assertSame('title', $postState->getBinding()->getPaths()[1] ?? null);
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testFlattenedAliasFromPathUsesAdapterOnlyWhenNoChildObjectExists(): void
	{
		[$session, $user] = $this->trackedUserWithPostsRelation();
		$user->newPostTitle = 'Alias';

		$p = $session->projection($user);
		$postTarget = $p->fromPath($user, 'posts')->create();

		self::assertInstanceOf(ManualProjectionTarget::class, $postTarget);
		self::assertNotSame($user, $postTarget->getTargetObject());
		self::assertInstanceOf(stdClass::class, $postTarget->getTargetObject());
	}

	public function testFlattenedAliasProjectionStillUsesSharedSelectionCompiler(): void
	{
		$contents = (string) file_get_contents(dirname(__DIR__, 3) . '/src/ORM/ManualProjection/ManualProjectionBuilder.php');

		self::assertStringContainsString('ProjectionSelectionNormalizer', $contents);
		self::assertStringContainsString('ProjectionBindingAssembler', $contents);
		self::assertStringContainsString('normalizeSelections', $contents);
		self::assertStringContainsString('bindingAssembler->assemble', $contents);
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testFlattenedAliasFromPathRegistersSelectionSourceOnlyWhenFieldAccessed(): void
	{
		[$session, $user] = $this->trackedUserWithPostsRelation();
		$user->newPostTitle = 'Alias';

		$p = $session->projection($user);
		$postTarget = $p->fromPath($user, 'posts')->create();

		self::assertNull($this->selectionSourceFromTarget($postTarget));

		$p->select($postTarget->title->as('newPostTitle'));

		self::assertInstanceOf(SelectQuery::class, $this->selectionSourceFromTarget($postTarget));
	}

	public function testAdapterObjectsAreRegisteredInNormalRepresentationStore(): void
	{
		$representations = new RepresentationStore();
		$tracker = new ManualProjectionRepresentationTracker($representations, new RecordStateStore());
		$record = RecordState::new($this->registry()->getCollection('users'), ['id' => 10]);

		$adapter = $tracker->trackAdapter($record);
		$state = $representations->get($adapter);

		self::assertInstanceOf(RepresentationState::class, $state);
		self::assertSame(10, $adapter->id);
		self::assertSame('id', $state->getBinding()->getPaths()[0]);
		self::assertTrue($state->getBinding()->getField('id')->isReadOnly());
	}

	public function testRelationApplierUsesNormalToManyRelationStore(): void
	{
		$toMany = new RelationStateStore();
		$applier = new ManualProjectionRelationApplier($toMany, new RelationStateStore());
		$owner = RecordState::new($this->registry()->getCollection('users'), ['id' => 10]);
		$target = new stdClass();

		$applier->applyTarget($owner, 'posts', RepresentationRelationCardinality::MANY, new RepresentationBinding(), $target);
		$relation = $toMany->get($owner, 'posts');

		self::assertInstanceOf(ToManyRelationState::class, $relation);
		self::assertSame([$target], $relation->getAdded());
	}

	public function testQueryAndManualAliasProjectionUseEquivalentPublicPaths(): void
	{
		$registry = $this->registry();
		$users = $registry->getCollection('users');
		$normalizer = new ProjectionSelectionNormalizer();
		$assembler = new ProjectionBindingAssembler();

		$query = new SelectQuery($users);
		$query->select($query->name->as('display_name'));
		$queryBinding = $assembler->assemble(
			$normalizer->normalizeSelections($query->getSelections()->getExplicit()),
			new QueryProjectionSourceResolver($query),
		);

		$manualSource = new SelectQuery($users);
		$record = RecordState::new($users);
		$manualResolver = new ManualProjectionSourceResolver();
		$manualResolver->rememberSource($manualSource, $record);
		$manualBinding = $assembler->assemble(
			$normalizer->normalizeExpressions([$manualSource->name->as('display_name')], ignoreUnsupported: false),
			$manualResolver,
			skipWhenMissing: true,
		);

		self::assertSame($queryBinding->getPaths(), $manualBinding->getPaths());
		self::assertSame('display_name', $queryBinding->getPaths()[0]);
		self::assertSame('name', $queryBinding->getField('display_name')->getField()->getFieldName());
		self::assertSame('name', $manualBinding->getField('display_name')->getField()->getFieldName());
		self::assertTrue($manualBinding->getField('display_name')->getField()->hasState());
	}

	public function testSourceResolversDoNotInspectAliasesOrBuildFieldBindings(): void
	{
		foreach ([
			dirname(__DIR__, 3) . '/src/ORM/Binding/QueryProjectionSourceResolver.php',
			dirname(__DIR__, 3) . '/src/ORM/ManualProjection/ManualProjectionSourceResolver.php',
		] as $path) {
			$contents = (string) file_get_contents($path);

			self::assertStringNotContainsString('AliasedExpression', $contents, $path);
			self::assertStringNotContainsString('SelectionItem', $contents, $path);
			self::assertStringNotContainsString('RepresentationFieldBinding', $contents, $path);
			self::assertStringNotContainsString('RecordFieldRef', $contents, $path);
		}
	}

	public function testCompiledFieldShapeRecordFieldRefsAreCreatedByBindingAssembler(): void
	{
		$root = dirname(__DIR__, 3);

		foreach ([
			$root . '/src/ORM/Binding/ProjectionSelectionNormalizer.php',
			$root . '/src/ORM/Binding/ProjectionFieldShape.php',
			$root . '/src/ORM/Binding/QueryProjectionSourceResolver.php',
			$root . '/src/ORM/ManualProjection/ManualProjectionSourceResolver.php',
		] as $path) {
			self::assertStringNotContainsString('RecordFieldRef', (string) file_get_contents($path), $path);
		}

		self::assertStringContainsString('RecordFieldRef::template', (string) file_get_contents($root . '/src/ORM/Binding/ProjectionBindingAssembler.php'));
		self::assertStringContainsString('RecordFieldRef::forState', (string) file_get_contents($root . '/src/ORM/Binding/ProjectionBindingAssembler.php'));
	}

	public function testHiddenIdentityPlanningRemainsOutsideManualProjectionPath(): void
	{
		$manualRoot = dirname(__DIR__, 3) . '/src/ORM/ManualProjection';

		foreach (glob($manualRoot . '/*.php') ?: [] as $path) {
			$contents = (string) file_get_contents($path);

			self::assertStringNotContainsString('ProjectionIdentityMap', $contents, $path);
			self::assertStringNotContainsString('SelectionTag::INTERNAL', $contents, $path);
			self::assertStringNotContainsString('generateInternalResultKey', $contents, $path);
		}
	}

	public function testManualProjectionDoesNotAddDurableProjectionStores(): void
	{
		$methods = get_class_methods('ON\Data\ORM\Session');

		foreach ([
			'getProjectionSources',
			'getProjectionRecords',
			'getProjectionRelations',
			'trackProjectionSource',
			'trackProjectionRelation',
		] as $method) {
			self::assertNotContains($method, $methods);
		}

		self::assertContains('getRecords', $methods);
		self::assertContains('getRepresentations', $methods);
		self::assertContains('getToOneRelations', $methods);
		self::assertContains('getToManyRelations', $methods);
	}

	private function registry(): Registry
	{
		$registry = new Registry();
		$registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		return $registry;
	}

	/**
	 * @return array{0: Session, 1: stdClass}
	 */
	private function trackedUserWithPostsRelation(): array
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
		$harness->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT)');
		$harness->exec('CREATE TABLE user_post (user_id INTEGER, post_id INTEGER)');
		$harness->exec("INSERT INTO users (id, name) VALUES (1, 'Ada')");

		$registry = new Registry();
		$registry->collection('posts')
			->table('posts')
			->primaryKey('id')
			->field('id', 'int')->autoIncrement(true)->end()
			->field('title', 'string')->end();
		$registry->collection('user_post')
			->table('user_post')
			->field('user_id', 'int')->end()
			->field('post_id', 'int')->end();
		$users = $registry->collection('users')
			->table('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		$users->relation('posts', M2MRelation::class)
			->collection('posts')
			->innerKey('id')
			->outerKey('id')
			->through('user_post')
				->innerKey('user_id')
				->outerKey('post_id')
				->end();

		$session = new Session($harness->commandExecutor);
		$query = $harness->database->query($users);
		$query->select($query->id, $query->name);
		$query->posts->fields('id', 'title');

		$user = $query->to(stdClass::class)->mutable($session)->fetchOne();
		self::assertInstanceOf(stdClass::class, $user);

		return [$session, $user];
	}

	private function selectionSourceFromTarget(ManualProjectionTarget $target): ?SelectQuery
	{
		$property = new ReflectionProperty(ManualProjectionTarget::class, 'selectionSource');
		$property->setAccessible(true);
		$value = $property->getValue($target);

		return $value instanceof SelectQuery ? $value : null;
	}
}
