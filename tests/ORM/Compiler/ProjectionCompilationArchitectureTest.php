<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Compiler;

use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\ORM\Compiler\ManualProjection\AllProperties;
use ON\Data\ORM\Compiler\ManualProjection\Builder;
use ON\Data\ORM\Compiler\ManualProjection\PathResolver;
use ON\Data\ORM\Compiler\ManualProjection\ProjectionCompiler;
use ON\Data\ORM\Compiler\ManualProjection\PropertyRef;
use ON\Data\ORM\Compiler\ManualProjection\RepresentationTracker;
use ON\Data\ORM\Compiler\ManualProjection\RootTarget;
use ON\Data\ORM\Compiler\ManualProjection\SourceResolver;
use ON\Data\ORM\Compiler\ManualProjection\Target;
use ON\Data\ORM\Compiler\ProjectionBindingAssembler;
use ON\Data\ORM\Compiler\ProjectionFieldShape;
use ON\Data\ORM\Compiler\ResolvedProjectionSource;
use ON\Data\ORM\Compiler\SelectQuery\ProjectionSelectionNormalizer;
use ON\Data\ORM\Compiler\SelectQuery\QueryProjectionSourceResolver;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Session;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationFieldStateItem;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStore;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use Tests\ON\Data\Smoke\Support\SqliteMemoryHarness;
use Tests\ON\Data\Support\RecordingCommandExecutor;

final class ProjectionCompilationArchitectureTest extends TestCase
{
	public function testRemovedProjectionIdentityProviderApiDoesNotExist(): void
	{
		$root = dirname(__DIR__, 3);

		self::assertDirectoryDoesNotExist($root . '/src/ORM/Binding');
		self::assertFileDoesNotExist($root . '/src/ORM/ManualProjection/ManualProjectionIdentityProvider.php');
		self::assertFalse(method_exists(ProjectionSelectionNormalizer::class, 'fieldForSelection'));
		self::assertFileDoesNotExist($root . '/src/ORM/Compiler/ProjectionSourceResolver.php');
		self::assertFileDoesNotExist($root . '/src/ORM/Compiler/ManualProjection/RelationApplier.php');
		self::assertFileExists($root . '/src/ORM/Compiler/ProjectionSourceResolverInterface.php');
		self::assertFileExists($root . '/src/ORM/Compiler/ResolvedProjectionSource.php');
		self::assertFalse(method_exists(SourceResolver::class, 'rememberSource'));
		self::assertFalse(method_exists(ResolvedProjectionSource::class, 'getRecordState'));
		self::assertFalse(method_exists(PathResolver::class, 'collectionFromBinding'));
	}

	public function testManualBuilderUsesProjectionCompilerNotAssemblerDirectly(): void
	{
		$constructor = (new ReflectionClass(Builder::class))->getConstructor();
		self::assertNotNull($constructor);

		$parameters = $constructor->getParameters();

		self::assertSame(ProjectionCompiler::class, $parameters[2]->getType()?->getName());
	}

	public function testManualBuilderDoesNotOwnExtractedProjectionInternals(): void
	{
		$contents = (string) file_get_contents(dirname(__DIR__, 3) . '/src/ORM/Compiler/ManualProjection/Builder.php');

		self::assertStringNotContainsString('RecordFieldRef', $contents);
		self::assertStringNotContainsString('new ToManyRelationState', $contents);
		self::assertStringNotContainsString('new ToOneRelationState', $contents);
		self::assertStringNotContainsString('relationBindingFromPath', $contents);
		self::assertStringNotContainsString('mergeBindings', $contents);
		self::assertStringNotContainsString('mirrorRelationTarget', $contents);
		self::assertStringNotContainsString('bindingAssembler->assemble', $contents);
		self::assertStringNotContainsString('attachPathTarget', $contents);
		self::assertStringNotContainsString('attachRelationTarget', $contents);
		self::assertStringNotContainsString('recordForExisting', $contents);
		self::assertStringNotContainsString('new RepresentationFieldStateItem', $contents);
		self::assertStringNotContainsString('resolveRecordForNewField', $contents);
		self::assertStringNotContainsString('RepresentationBindingMerger', $contents);
		self::assertStringNotContainsString('bindingMerger', $contents);
	}

	public function testManualBuilderDelegatesProjectionApplicationWithoutBindingMerger(): void
	{
		$method = (new ReflectionClass(RepresentationTracker::class))->getMethod('applyManualProjection');
		$parameterTypes = array_map(
			static fn ($parameter): ?string => $parameter->getType()?->getName(),
			$method->getParameters()
		);

		self::assertSame([
			'object',
			RepresentationBinding::class,
			'array',
		], $parameterTypes);
	}

	public function testDeclarationWrapperClassesDoNotExist(): void
	{
		$root = dirname(__DIR__, 3);

		self::assertFileDoesNotExist($root . '/src/ORM/ManualProjection/ProjectionSourceDeclaration.php');
		self::assertFileDoesNotExist($root . '/src/ORM/ManualProjection/ProjectionPathDeclaration.php');
	}

	public function testManualProjectionNamespaceDoesNotExist(): void
	{
		$root = dirname(__DIR__, 3);

		self::assertDirectoryDoesNotExist($root . '/src/ORM/ManualProjection');
	}

	public function testSelectQueryCompilerDoesNotReferenceManualProjection(): void
	{
		$root = dirname(__DIR__, 3) . '/src/ORM/Compiler/SelectQuery';

		foreach (glob($root . '/*.php') ?: [] as $path) {
			$contents = (string) file_get_contents($path);

			self::assertStringNotContainsString('ON\Data\ORM\Compiler\ManualProjection', $contents, $path);
		}
	}

	public function testManualProjectionCompilerDoesNotReferenceSelectQuery(): void
	{
		$root = dirname(__DIR__, 3) . '/src/ORM/Compiler/ManualProjection';

		foreach (glob($root . '/*.php') ?: [] as $path) {
			$contents = (string) file_get_contents($path);

			self::assertStringNotContainsString('ON\Data\Query\SelectQuery', $contents, $path);
			self::assertStringNotContainsString('QuerySourceInterface', $contents, $path);
		}

		$compilerContents = (string) file_get_contents(dirname(__DIR__, 3) . '/src/ORM/Compiler/ManualProjection/ProjectionCompiler.php');
		self::assertStringNotContainsString('ON\Data\Query\SelectQuery', $compilerContents);
		self::assertStringNotContainsString('QuerySourceInterface', $compilerContents);
	}

	public function testProjectionPathSourceWasReplacedByManualProjectionTarget(): void
	{
		$root = dirname(__DIR__, 3);

		self::assertFileDoesNotExist($root . '/src/ORM/ManualProjection/ProjectionPathSource.php');
		self::assertFileExists($root . '/src/ORM/Compiler/ManualProjection/Target.php');
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testObjectShapedFromPathCreateReturnsManualPropertyRefsWithoutSelectQuery(): void
	{
		[$session, $user] = $this->trackedUserWithPostsRelation();
		$post = new stdClass();
		$post->title = 'Direct branch';

		$target = $session
			->projection($post)
			->fromPath($user, 'posts')
			->create();

		self::assertInstanceOf(Target::class, $target);
		self::assertInstanceOf(PropertyRef::class, $target->title);
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

		self::assertInstanceOf(Target::class, $postTarget);
		self::assertNotSame($user, $postTarget->getTargetObject());
		self::assertInstanceOf(stdClass::class, $postTarget->getTargetObject());
	}

	public function testManualBuilderBuildsProjectionFieldShapesDirectly(): void
	{
		$contents = (string) file_get_contents(dirname(__DIR__, 3) . '/src/ORM/Compiler/ManualProjection/Builder.php');

		self::assertStringContainsString('ProjectionFieldShape', $contents);
		self::assertStringContainsString('projectionCompiler->compile', $contents);
		self::assertStringNotContainsString('ProjectionSelectionNormalizer', $contents);
		self::assertStringNotContainsString('SelectQuery\\ProjectionCompiler', $contents);
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testFlattenedAliasFromPathUsesManualPropertyRefs(): void
	{
		[$session, $user] = $this->trackedUserWithPostsRelation();
		$user->newPostTitle = 'Alias';

		$p = $session->projection($user);
		$postTarget = $p->fromPath($user, 'posts')->create();
		$property = $postTarget->title->as('newPostTitle');

		self::assertInstanceOf(PropertyRef::class, $property);
		self::assertSame('newPostTitle', $property->getPublicPath());
		self::assertSame('title', $property->getFieldName());
	}

	public function testAdapterObjectsAreRegisteredInNormalRepresentationStore(): void
	{
		$representations = new RepresentationStore();
		$tracker = new RepresentationTracker($representations, new RecordStateStore());
		$record = RecordState::new($this->registry()->getCollection('users'), ['id' => 10]);

		$adapter = $tracker->trackAdapter($record);
		$state = $representations->get($adapter);

		self::assertInstanceOf(RepresentationState::class, $state);
		self::assertSame(10, $adapter->id);
		self::assertSame('id', $state->getBinding()->getPaths()[0]);
		self::assertTrue($state->getBinding()->getField('id')->isReadOnly());
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
			$users,
		);

		$record = RecordState::new($users);
		$rootTarget = new RootTarget($record);
		$manualBinding = (new ProjectionCompiler())->compile(
			[new ProjectionFieldShape('display_name', $rootTarget, 'name')],
		);

		self::assertSame($queryBinding->getPaths(), $manualBinding->getPaths());
		self::assertSame('display_name', $queryBinding->getPaths()[0]);
		self::assertSame('name', $queryBinding->getField('display_name')->getFieldName());
		self::assertSame('name', $manualBinding->getField('display_name')->getFieldName());
		self::assertSame('users', $manualBinding->getField('display_name')->getCollectionName());
	}

	public function testSourceResolversDoNotInspectAliasesOrBuildFieldBindings(): void
	{
		foreach ([
			dirname(__DIR__, 3) . '/src/ORM/Compiler/SelectQuery/QueryProjectionSourceResolver.php',
			dirname(__DIR__, 3) . '/src/ORM/Compiler/ManualProjection/SourceResolver.php',
		] as $path) {
			$contents = (string) file_get_contents($path);

			self::assertStringNotContainsString('AliasedExpression', $contents, $path);
			self::assertStringNotContainsString('SelectionItem', $contents, $path);
			self::assertStringNotContainsString('RepresentationFieldBinding', $contents, $path);
			self::assertStringNotContainsString('RecordFieldRef', $contents, $path);
		}
	}

	public function testProjectionFieldBindingsAreCreatedByBindingAssembler(): void
	{
		$root = dirname(__DIR__, 3);

		foreach ([
			$root . '/src/ORM/Compiler/SelectQuery/ProjectionSelectionNormalizer.php',
			$root . '/src/ORM/Compiler/ProjectionFieldShape.php',
			$root . '/src/ORM/Compiler/SelectQuery/QueryProjectionSourceResolver.php',
			$root . '/src/ORM/Compiler/ManualProjection/SourceResolver.php',
			$root . '/src/ORM/Compiler/SelectQuery/ProjectionCompiler.php',
		] as $path) {
			self::assertStringNotContainsString('RecordFieldRef', (string) file_get_contents($path), $path);
			self::assertStringNotContainsString('new RepresentationFieldBinding', (string) file_get_contents($path), $path);
		}

		$assembler = (string) file_get_contents($root . '/src/ORM/Compiler/ProjectionBindingAssembler.php');
		self::assertStringNotContainsString('RecordFieldRef', $assembler);
		self::assertStringContainsString('new RepresentationFieldBinding', $assembler);
	}

	public function testHiddenIdentityPlanningRemainsOutsideManualProjectionPath(): void
	{
		$manualRoot = dirname(__DIR__, 3) . '/src/ORM/Compiler/ManualProjection';

		foreach (glob($manualRoot . '/*.php') ?: [] as $path) {
			$contents = (string) file_get_contents($path);

			self::assertStringNotContainsString('ProjectionIdentityColumns', $contents, $path);
			self::assertStringNotContainsString('SelectionTag::INTERNAL', $contents, $path);
			self::assertStringNotContainsString('generateInternalResultKey', $contents, $path);
		}
	}

	public function testManualProjectionNamespaceDoesNotImportSelectQuery(): void
	{
		$manualRoot = dirname(__DIR__, 3) . '/src/ORM/Compiler/ManualProjection';

		foreach (glob($manualRoot . '/*.php') ?: [] as $path) {
			$contents = (string) file_get_contents($path);

			self::assertStringNotContainsString('ON\Data\Query\SelectQuery', $contents, $path);
			self::assertStringNotContainsString('SelectQuery\\ProjectionCompiler', $contents, $path);
			self::assertStringNotContainsString('ProjectionSelectionNormalizer', $contents, $path);
		}
	}

	public function testManualProjectionTargetDoesNotImplementQuerySourceInterface(): void
	{
		self::assertFalse(is_subclass_of(Target::class, QuerySourceInterface::class));
		self::assertFalse(is_subclass_of(RootTarget::class, QuerySourceInterface::class));
	}

	public function testManualPropertyRefsDoNotImplementQueryExpressionInterfaces(): void
	{
		self::assertFalse(is_subclass_of(PropertyRef::class, ValueExpressionInterface::class));
		self::assertFalse(is_subclass_of(AllProperties::class, ValueExpressionInterface::class));
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testManualRootPropertiesExpandAllCollectionFields(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT)');
		$registry = new Registry();
		$users = $registry->collection('users')
			->table('users')
			->primaryKey('id')
			->field('id', 'int')->autoIncrement(true)->end()
			->field('name', 'string')->end()
			->field('email', 'string')->end();
		$session = new Session($harness->commandExecutor);
		$row = new stdClass();
		$row->name = 'Ada';
		$row->email = 'ada@example.test';

		$p = $session->projection($row);
		$u = $p->from($users)->create();
		$p->properties($u->all())->end();

		$state = $session->getRepresentations()->get($row);
		self::assertInstanceOf(RepresentationState::class, $state);
		self::assertSame(['name', 'email'], array_values(array_filter(
			$state->getBinding()->getPaths(),
			static fn (string $path): bool => $path !== 'id',
		)));
	}

	public function testManualBuilderEndReturnsRepresentationAndClearsPropertyShapes(): void
	{
		$users = $this->registry()->getCollection('users');
		$session = new Session(new RecordingCommandExecutor());
		$row = new stdClass();
		$row->name = 'Ada';

		$builder = $session->projection($row);
		$target = $builder->from($users)->create(['id' => 10]);

		self::assertSame($row, $builder->properties($target->name)->end());
		self::assertSame($row, $builder->end());
		self::assertSame(['name'], $session->getRepresentations()->get($row)->getBinding()->getPaths());
	}

	public function testManualProjectionAttachmentAddsRootFieldToExistingRootRecord(): void
	{
		$users = $this->registry()->getCollection('users');
		$record = RecordState::new($users, ['id' => 10]);
		$representation = new stdClass();
		$representations = new RepresentationStore();
		$rootBinding = new RepresentationBinding($users);
		$id = new RepresentationFieldBinding('id', $users, 'id');
		$rootBinding->addField($id);
		$representations->add($representation, new RepresentationState($rootBinding, [
			new RepresentationFieldStateItem($id, $record, 'id', $record->getRevision()),
		]));

		$manualBinding = new RepresentationBinding($users);
		$manualBinding->addField(new RepresentationFieldBinding('name', $users, 'name'));

		(new RepresentationTracker($representations, new RecordStateStore()))->applyManualProjection(
			$representation,
			$manualBinding,
			[new ProjectionFieldShape('name', new RootTarget($record), 'name')],
		);

		$state = $representations->get($representation);
		self::assertInstanceOf(RepresentationState::class, $state);
		self::assertSame($record, $state->getFieldItem('name')->getRecord());
	}

	public function testManualProjectionAttachmentUsesSourcePathWhenCollectionsMatch(): void
	{
		$users = $this->registry()->getCollection('users');
		$rootRecord = RecordState::new($users, ['id' => 10]);
		$managerRecord = RecordState::new($users, ['id' => 20]);
		$representation = new stdClass();
		$representations = new RepresentationStore();
		$existingBinding = new RepresentationBinding($users);
		$rootField = new RepresentationFieldBinding('name', $users, 'name');
		$managerField = new RepresentationFieldBinding('managerName', $users, 'name', sourcePath: ['manager']);
		$existingBinding->addField($rootField);
		$existingBinding->addField($managerField);
		$representations->add($representation, new RepresentationState($existingBinding, [
			new RepresentationFieldStateItem($rootField, $rootRecord, 'name', $rootRecord->getRevision()),
			new RepresentationFieldStateItem($managerField, $managerRecord, 'name', $managerRecord->getRevision()),
		]));

		$manualBinding = new RepresentationBinding($users);
		$manualBinding->addField(new RepresentationFieldBinding('managerEmail', $users, 'email', sourcePath: ['manager']));

		(new RepresentationTracker($representations, new RecordStateStore()))->applyManualProjection(
			$representation,
			$manualBinding,
			[],
		);

		$state = $representations->get($representation);
		self::assertInstanceOf(RepresentationState::class, $state);
		self::assertSame($managerRecord, $state->getFieldItem('managerEmail')->getRecord());
		self::assertNotSame($rootRecord, $state->getFieldItem('managerEmail')->getRecord());
	}

	public function testManualProjectionAttachmentThrowsWhenSourcePathCannotResolve(): void
	{
		$users = $this->registry()->getCollection('users');
		$record = RecordState::new($users, ['id' => 10]);
		$representation = new stdClass();
		$representations = new RepresentationStore();
		$existingBinding = new RepresentationBinding($users);
		$rootField = new RepresentationFieldBinding('name', $users, 'name');
		$existingBinding->addField($rootField);
		$representations->add($representation, new RepresentationState($existingBinding, [
			new RepresentationFieldStateItem($rootField, $record, 'name', $record->getRevision()),
		]));

		$manualBinding = new RepresentationBinding($users);
		$manualBinding->addField(new RepresentationFieldBinding('managerEmail', $users, 'email', sourcePath: ['manager']));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage("Cannot attach manual projection field 'managerEmail'");

		(new RepresentationTracker($representations, new RecordStateStore()))->applyManualProjection(
			$representation,
			$manualBinding,
			[],
		);
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testFromPathResolvesTrackedBindingWithoutSelectQuery(): void
	{
		[$session, $user] = $this->trackedUserWithPostsRelation();
		$pathResolver = new PathResolver($session->getRepresentations());

		$resolved = $pathResolver->resolve($user, 'posts');

		self::assertSame('posts', $resolved->getPath());
		self::assertSame('posts', $resolved->getRelationName());
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
}
