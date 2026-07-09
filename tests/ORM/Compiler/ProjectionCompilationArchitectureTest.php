<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Compiler;

use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\ORM\Representation\Schema\Manual\AllProperties;
use ON\Data\ORM\Representation\Schema\Manual\Builder;
use ON\Data\ORM\Representation\Schema\Manual\PathResolver;
use ON\Data\ORM\Representation\Schema\Manual\ManualRepresentationSchemaCompiler;
use ON\Data\ORM\Representation\Schema\Manual\ManualRepresentationSourceFactory;
use ON\Data\ORM\Representation\Schema\Manual\PropertyRef;
use ON\Data\ORM\Representation\Schema\Manual\RelationRef;
use ON\Data\ORM\Representation\State\Manual\ManualRepresentationStateBuilder;
use ON\Data\ORM\Representation\Schema\Manual\RootRepresentationSource;
use ON\Data\ORM\Representation\Schema\Manual\ManualRepresentationSourceResolver;
use ON\Data\ORM\Representation\Schema\Manual\RelationRepresentationSource;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationFieldShape;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationSchemaAssembler;
use ON\Data\ORM\Representation\Schema\Shape\ResolvedRepresentationSource;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationSelectionNormalizer;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationSourceResolver;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Session;
use ON\Data\ORM\SessionContext;
use ON\Data\ORM\Representation\Sync\RepresentationAttachmentMode;
use Tests\ON\Data\Support\RecordingCommandExecutor;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\State\RepresentationFieldStateItem;
use ON\Data\ORM\Representation\Schema\RepresentationRelationSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\State\RepresentationStateStore;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use Tests\ON\Data\Smoke\Support\SqliteMemoryHarness;

final class ProjectionCompilationArchitectureTest extends TestCase
{
	public function testRemovedProjectionIdentityProviderApiDoesNotExist(): void
	{
		$root = dirname(__DIR__, 3);

		self::assertDirectoryDoesNotExist($root . '/src/ORM/Binding');
		self::assertFileDoesNotExist($root . '/src/ORM/ManualProjection/ManualProjectionIdentityProvider.php');
		self::assertFalse(method_exists(QueryRepresentationSelectionNormalizer::class, 'fieldForSelection'));
		self::assertFileDoesNotExist($root . '/src/ORM/Compiler/ProjectionSourceResolver.php');
		self::assertFileDoesNotExist($root . '/src/ORM/Representation/Schema/Manual/RelationApplier.php');
		self::assertFileExists($root . '/src/ORM/Representation/Schema/Shape/RepresentationSourceResolverInterface.php');
		self::assertFileExists($root . '/src/ORM/Representation/Schema/Shape/ResolvedRepresentationSource.php');
		self::assertFalse(method_exists(ManualRepresentationSourceResolver::class, 'rememberSource'));
		self::assertFalse(method_exists(ResolvedRepresentationSource::class, 'getRecordState'));
		self::assertFalse(method_exists(PathResolver::class, 'collectionFromBinding'));
	}

	public function testManualBuilderUsesProjectionCompilerNotAssemblerDirectly(): void
	{
		$constructor = (new ReflectionClass(Builder::class))->getConstructor();
		self::assertNotNull($constructor);

		$parameters = $constructor->getParameters();

		self::assertSame(ManualRepresentationSchemaCompiler::class, $parameters[2]->getType()?->getName());
	}

	public function testManualBuilderDoesNotOwnExtractedProjectionInternals(): void
	{
		$contents = (string) file_get_contents(dirname(__DIR__, 3) . '/src/ORM/Representation/Schema/Manual/Builder.php');

		self::assertStringNotContainsString('new ToManyRelationState', $contents);
		self::assertStringNotContainsString('new ToOneRelationState', $contents);
		self::assertStringNotContainsString('relationSchemaFromPath', $contents);
		self::assertStringNotContainsString('mergeBindings', $contents);
		self::assertStringNotContainsString('mirrorRelationTarget', $contents);
		self::assertStringNotContainsString('schemaAssembler->assemble', $contents);
		self::assertStringNotContainsString('attachPathTarget', $contents);
		self::assertStringNotContainsString('attachRelationTarget', $contents);
		self::assertStringNotContainsString('recordForExisting', $contents);
		self::assertStringNotContainsString('new RepresentationFieldStateItem', $contents);
		self::assertStringNotContainsString('resolveRecordForNewField', $contents);
		self::assertStringNotContainsString('RepresentationSchemaMerger', $contents);
		self::assertStringNotContainsString('schemaMerger', $contents);
	}

	public function testManualBuilderDelegatesProjectionApplicationToStateBuilder(): void
	{
		$method = (new ReflectionClass(ManualRepresentationStateBuilder::class))->getMethod('buildOverlay');
		$parameterTypes = array_map(
			static fn ($parameter): ?string => $parameter->getType()?->getName(),
			$method->getParameters()
		);

		self::assertSame([
			RepresentationState::class,
			RepresentationSchema::class,
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
		$root = dirname(__DIR__, 3) . '/src/ORM/Representation/Schema/Query';

		foreach (glob($root . '/*.php') ?: [] as $path) {
			$contents = (string) file_get_contents($path);

			self::assertStringNotContainsString('ON\Data\ORM\Representation\Schema\Manual', $contents, $path);
		}
	}

	public function testManualProjectionCompilerDoesNotReferenceSelectQuery(): void
	{
		$root = dirname(__DIR__, 3) . '/src/ORM/Representation/Schema/Manual';

		foreach (glob($root . '/*.php') ?: [] as $path) {
			$contents = (string) file_get_contents($path);

			self::assertStringNotContainsString('ON\Data\Query\SelectQuery', $contents, $path);
			self::assertStringNotContainsString('QuerySourceInterface', $contents, $path);
		}

		$compilerContents = (string) file_get_contents(dirname(__DIR__, 3) . '/src/ORM/Representation/Schema/Manual/ManualRepresentationSchemaCompiler.php');
		self::assertStringNotContainsString('ON\Data\Query\SelectQuery', $compilerContents);
		self::assertStringNotContainsString('QuerySourceInterface', $compilerContents);
	}

	public function testProjectionPathSourceWasReplacedByRelationRepresentationSource(): void
	{
		$root = dirname(__DIR__, 3);

		self::assertFileDoesNotExist($root . '/src/ORM/ManualProjection/ProjectionPathSource.php');
		self::assertFileExists($root . '/src/ORM/Representation/Schema/Manual/RelationRepresentationSource.php');
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

		self::assertInstanceOf(RelationRepresentationSource::class, $target);
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
	public function testObjectShapedFromPathAppliesRelatedSchemaBranchDirectly(): void
	{
		[$session, $user] = $this->trackedUserWithPostsRelation();
		$userSchema = $session->getRepresentations()->get($user)->getSchema();
		$relatedSchema = $userSchema->getRelation('posts')->getRelatedSchema();
		$post = new stdClass();
		$post->title = 'Branch reuse';

		$session
			->projection($post)
			->fromPath($user, 'posts')
			->create()
			->end();

		$postState = $session->getRepresentations()->get($post);
		self::assertInstanceOf(RepresentationState::class, $postState);
		self::assertSame($relatedSchema->getPaths(), $postState->getSchema()->getPaths());
		self::assertSame('title', $postState->getSchema()->getPaths()[1] ?? null);
	}

	public function testManualRelationTargetEnrollmentBuildsSkipWhenMissingFieldItemsOnly(): void
	{
		$registry = new Registry();
		$posts = $registry->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end();
		$comments = $registry->collection('comments')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('body', 'string')->end();
		$posts->hasMany('comments', 'comments');
		$postSchema = new RepresentationSchema($posts);
		$postSchema->addField(new RepresentationFieldSchema('title', $posts, 'title'));
		$postSchema->addRelation(new RepresentationRelationSchema('comments', $posts, 'comments', new RepresentationSchema($comments)));
		$session = new Session(new RecordingCommandExecutor());
		$representation = new stdClass();
		$ownerRecord = RecordState::new($posts, ['title' => 'Draft']);
		$session->getRecords()->add($ownerRecord);
		$session->adoptRecord($representation, $postSchema, $ownerRecord);

		$target = (new Builder($session, $representation))
			->fromPath($representation, 'comments')
			->create(['body' => 'Note']);

		$state = $session->getRepresentations()->get($target->getTargetObject());
		self::assertInstanceOf(RepresentationState::class, $state);
		self::assertSame('comments', $state->getSchema()->getCollectionName());
		self::assertSame([], $state->getRelationItems());
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testFlattenedAliasFromPathUsesAdapterOnlyWhenNoChildObjectExists(): void
	{
		[$session, $user] = $this->trackedUserWithPostsRelation();
		$user->newPostTitle = 'Alias';

		$p = $session->projection($user);
		$postTarget = $p->fromPath($user, 'posts')->create();

		self::assertInstanceOf(RelationRepresentationSource::class, $postTarget);
		self::assertNotSame($user, $postTarget->getTargetObject());
		self::assertInstanceOf(stdClass::class, $postTarget->getTargetObject());
	}

	public function testManualBuilderBuildsProjectionFieldShapesDirectly(): void
	{
		$contents = (string) file_get_contents(dirname(__DIR__, 3) . '/src/ORM/Representation/Schema/Manual/Builder.php');

		self::assertStringContainsString('RepresentationFieldShape', $contents);
		self::assertStringContainsString('projectionCompiler->compile', $contents);
		self::assertStringNotContainsString('QueryRepresentationSelectionNormalizer', $contents);
		self::assertStringNotContainsString('SelectQuery\\ManualRepresentationSchemaCompiler', $contents);
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

	public function testPrimaryKeyRelationTargetBuildsReadOnlyIdentityState(): void
	{
		$registry = new Registry();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		$profiles = $registry->collection('profiles')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		$users->hasOne('profile', 'profiles');
		$session = new Session(new RecordingCommandExecutor());
		$representation = new stdClass();
		$ownerRecord = RecordState::new($users, ['id' => 1, 'name' => 'Ada']);
		$session->getRecords()->add($ownerRecord);
		$target = (new Builder($session, $representation))->create(
			new RelationRef(new RootRepresentationSource($ownerRecord), 'profile', $users->getRelation('profile')),
			['id' => 10, 'name' => 'Profile'],
		);

		$adapter = $target->getTargetObject();
		$state = $session->getRepresentations()->get($adapter);

		self::assertInstanceOf(RepresentationState::class, $state);
		self::assertSame(10, $adapter->id);
		self::assertSame('id', $state->getSchema()->getPaths()[0]);
		self::assertTrue($state->getSchema()->getField('id')->isReadOnly());
	}

	public function testQueryAndManualAliasProjectionUseEquivalentPublicPaths(): void
	{
		$registry = $this->registry();
		$users = $registry->getCollection('users');
		$normalizer = new QueryRepresentationSelectionNormalizer();
		$assembler = new RepresentationSchemaAssembler();

		$query = new SelectQuery($users);
		$query->select($query->name->as('display_name'));
		$querySchema = $assembler->assemble(
			$normalizer->normalizeSelections($query->getSelections()->getExplicit()),
			new QueryRepresentationSourceResolver($query),
			$users,
		);

		$record = RecordState::new($users);
		$rootTarget = new RootRepresentationSource($record);
		$manualSchema = (new ManualRepresentationSchemaCompiler())->compile(
			[new RepresentationFieldShape('display_name', $rootTarget, 'name')],
		);

		self::assertSame($querySchema->getPaths(), $manualSchema->getPaths());
		self::assertSame('display_name', $querySchema->getPaths()[0]);
		self::assertSame('name', $querySchema->getField('display_name')->getFieldName());
		self::assertSame('name', $manualSchema->getField('display_name')->getFieldName());
		self::assertSame('users', $manualSchema->getField('display_name')->getCollectionName());
	}

	public function testSourceResolversDoNotInspectAliasesOrBuildFieldSchemas(): void
	{
		foreach ([
			dirname(__DIR__, 3) . '/src/ORM/Representation/Schema/Query/QueryRepresentationSourceResolver.php',
			dirname(__DIR__, 3) . '/src/ORM/Representation/Schema/Manual/ManualRepresentationSourceResolver.php',
		] as $path) {
			$contents = (string) file_get_contents($path);

			self::assertStringNotContainsString('AliasedExpression', $contents, $path);
			self::assertStringNotContainsString('SelectionItem', $contents, $path);
			self::assertStringNotContainsString('RepresentationFieldSchema', $contents, $path);
		}
	}

	public function testProjectionFieldSchemasAreCreatedBySchemaAssembler(): void
	{
		$root = dirname(__DIR__, 3);

		foreach ([
			$root . '/src/ORM/Representation/Schema/Query/QueryRepresentationSelectionNormalizer.php',
			$root . '/src/ORM/Representation/Schema/Shape/RepresentationFieldShape.php',
			$root . '/src/ORM/Representation/Schema/Query/QueryRepresentationSourceResolver.php',
			$root . '/src/ORM/Representation/Schema/Manual/ManualRepresentationSourceResolver.php',
			$root . '/src/ORM/Representation/Schema/Manual/ManualRepresentationSchemaCompiler.php',
		] as $path) {
			self::assertStringNotContainsString('new RepresentationFieldSchema', (string) file_get_contents($path), $path);
		}

		$assembler = (string) file_get_contents($root . '/src/ORM/Representation/Schema/Shape/RepresentationSchemaAssembler.php');
		self::assertStringContainsString('new RepresentationFieldSchema', $assembler);
	}

	public function testHiddenIdentityPlanningRemainsOutsideManualProjectionPath(): void
	{
		$manualRoot = dirname(__DIR__, 3) . '/src/ORM/Representation/Schema/Manual';

		foreach (glob($manualRoot . '/*.php') ?: [] as $path) {
			$contents = (string) file_get_contents($path);

			self::assertStringNotContainsString('QueryRepresentationIdentityColumns', $contents, $path);
			self::assertStringNotContainsString('SelectionTag::INTERNAL', $contents, $path);
			self::assertStringNotContainsString('generateInternalResultKey', $contents, $path);
		}
	}

	public function testManualProjectionNamespaceDoesNotImportSelectQuery(): void
	{
		$manualRoot = dirname(__DIR__, 3) . '/src/ORM/Representation/Schema/Manual';

		foreach (glob($manualRoot . '/*.php') ?: [] as $path) {
			$contents = (string) file_get_contents($path);

			self::assertStringNotContainsString('ON\Data\Query\SelectQuery', $contents, $path);
			self::assertStringNotContainsString('SelectQuery\\ManualRepresentationSchemaCompiler', $contents, $path);
			self::assertStringNotContainsString('QueryRepresentationSelectionNormalizer', $contents, $path);
		}
	}

	public function testManualProjectionTargetDoesNotImplementQuerySourceInterface(): void
	{
		self::assertFalse(is_subclass_of(RelationRepresentationSource::class, QuerySourceInterface::class));
		self::assertFalse(is_subclass_of(RootRepresentationSource::class, QuerySourceInterface::class));
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
			$state->getSchema()->getPaths(),
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
		self::assertSame(['name'], $session->getRepresentations()->get($row)->getSchema()->getPaths());
	}

	public function testTrackedResolvesRecordForEachObjectInSameCollection(): void
	{
		$users = $this->registry()->getCollection('users');
		$session = new Session(new RecordingCommandExecutor());

		$ada = new stdClass();
		$pa = $session->projection($ada);
		$a = $pa->from($users)->existing(['id' => 1], ['id' => 1, 'name' => 'Ada']);
		$pa->properties($a->name)->end();

		$bob = new stdClass();
		$pb = $session->projection($bob);
		$b = $pb->from($users)->existing(['id' => 2], ['id' => 2, 'name' => 'Bob']);
		$pb->properties($b->name)->end();

		$adaTracked = $session->projection($ada)->from($users)->tracked();
		$bobTracked = $session->projection($bob)->from($users)->tracked();

		$adaState = $session->getRepresentations()->get($ada);
		$bobState = $session->getRepresentations()->get($bob);
		self::assertInstanceOf(RepresentationState::class, $adaState);
		self::assertInstanceOf(RepresentationState::class, $bobState);

		self::assertSame($adaState->getFieldItem('name')->getRecord(), $adaTracked->getTargetRecord());
		self::assertSame($bobState->getFieldItem('name')->getRecord(), $bobTracked->getTargetRecord());
		self::assertNotSame($adaTracked->getTargetRecord(), $bobTracked->getTargetRecord());
	}

	public function testManualProjectionAttachmentAddsRootFieldToExistingRootRecord(): void
	{
		$users = $this->registry()->getCollection('users');
		$record = RecordState::new($users, ['id' => 10]);
		$representation = new stdClass();
		$representations = new RepresentationStateStore();
		$rootSchema = new RepresentationSchema($users);
		$id = new RepresentationFieldSchema('id', $users, 'id');
		$rootSchema->addField($id);
		$representations->add($representation, new RepresentationState($rootSchema, [
			new RepresentationFieldStateItem($id, $record, 'id', $record->getRevision()),
		]));

		$manualSchema = new RepresentationSchema($users);
		$manualSchema->addField(new RepresentationFieldSchema('name', $users, 'name'));

		$builder = new ManualRepresentationStateBuilder();
		$existingState = $representations->get($representation);
		$session = new Session(
			new RecordingCommandExecutor(),
			context: new SessionContext(new RecordStateStore(), $representations),
		);
		$session->adopt(
			$representation,
			$builder->buildOverlay(
				$existingState instanceof RepresentationState ? $existingState : null,
				$manualSchema,
				[new RepresentationFieldShape('name', new RootRepresentationSource($record), 'name')],
			),
			RepresentationAttachmentMode::Replace,
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
		$representations = new RepresentationStateStore();
		$existingSchema = new RepresentationSchema($users);
		$rootField = new RepresentationFieldSchema('name', $users, 'name');
		$managerField = new RepresentationFieldSchema('managerName', $users, 'name', sourcePath: ['manager']);
		$existingSchema->addField($rootField);
		$existingSchema->addField($managerField);
		$representations->add($representation, new RepresentationState($existingSchema, [
			new RepresentationFieldStateItem($rootField, $rootRecord, 'name', $rootRecord->getRevision()),
			new RepresentationFieldStateItem($managerField, $managerRecord, 'name', $managerRecord->getRevision()),
		]));

		$manualSchema = new RepresentationSchema($users);
		$manualSchema->addField(new RepresentationFieldSchema('managerEmail', $users, 'email', sourcePath: ['manager']));

		$builder = new ManualRepresentationStateBuilder();
		$existingState = $representations->get($representation);
		$session = new Session(
			new RecordingCommandExecutor(),
			context: new SessionContext(new RecordStateStore(), $representations),
		);
		$session->adopt(
			$representation,
			$builder->buildOverlay($existingState instanceof RepresentationState ? $existingState : null, $manualSchema, []),
			RepresentationAttachmentMode::Replace,
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
		$representations = new RepresentationStateStore();
		$existingSchema = new RepresentationSchema($users);
		$rootField = new RepresentationFieldSchema('name', $users, 'name');
		$existingSchema->addField($rootField);
		$representations->add($representation, new RepresentationState($existingSchema, [
			new RepresentationFieldStateItem($rootField, $record, 'name', $record->getRevision()),
		]));

		$manualSchema = new RepresentationSchema($users);
		$manualSchema->addField(new RepresentationFieldSchema('managerEmail', $users, 'email', sourcePath: ['manager']));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage("Cannot attach manual projection field 'managerEmail'");

		(new ManualRepresentationStateBuilder())->buildOverlay(
			$representations->get($representation),
			$manualSchema,
			[],
		);
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testFromPathResolvesTrackedSchemaWithoutSelectQuery(): void
	{
		[$session, $user] = $this->trackedUserWithPostsRelation();
		$pathResolver = new PathResolver($session->getRepresentations());

		$resolved = $pathResolver->resolve($user, 'posts');

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
