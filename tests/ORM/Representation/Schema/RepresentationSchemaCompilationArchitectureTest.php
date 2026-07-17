<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Representation\Schema;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationSchemaCompiler;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationSelectionNormalizer;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationSourceResolver;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\RepresentationRelationSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationSchemaAssembler;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Session;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\ORM\Support\OrmFixture;
use Tests\ON\Data\Support\RecordingCommandExecutor;

final class RepresentationSchemaCompilationArchitectureTest extends TestCase
{
	use OrmFixture;

	public function testRemovedLegacyProjectionIdentityProviderApiDoesNotExist(): void
	{
		$root = dirname(__DIR__, 4);

		self::assertDirectoryDoesNotExist($root . '/src/ORM/Binding');
		self::assertFileDoesNotExist($root . '/src/ORM/ManualProjection/ManualProjectionIdentityProvider.php');
		self::assertFalse(method_exists(QueryRepresentationSelectionNormalizer::class, 'fieldForSelection'));
		self::assertFileDoesNotExist($root . '/src/ORM/Compiler/ProjectionSourceResolver.php');
		self::assertFileExists($root . '/src/ORM/Representation/Schema/Shape/RepresentationSourceResolverInterface.php');
		self::assertFileExists($root . '/src/ORM/Representation/Schema/Shape/ResolvedRepresentationSource.php');
	}

	public function testDeclarationWrapperClassesDoNotExist(): void
	{
		$root = dirname(__DIR__, 4);

		self::assertFileDoesNotExist($root . '/src/ORM/ManualProjection/ProjectionSourceDeclaration.php');
		self::assertFileDoesNotExist($root . '/src/ORM/ManualProjection/ProjectionPathDeclaration.php');
	}

	public function testLegacyManualProjectionNamespaceDoesNotExist(): void
	{
		$root = dirname(__DIR__, 4);

		self::assertDirectoryDoesNotExist($root . '/src/ORM/ManualProjection');
	}

	public function testSelectQueryCompilerDoesNotReferenceManualRepresentationSchema(): void
	{
		$root = dirname(__DIR__, 4) . '/src/ORM/Representation/Schema/Query';

		foreach (glob($root . '/*.php') ?: [] as $path) {
			$contents = (string) file_get_contents($path);

			self::assertStringNotContainsString('ON\Data\ORM\Representation\Schema\Manual', $contents, $path);
		}
	}

	public function testQuerySourceResolverDoesNotInspectAliasesOrBuildFieldSchemas(): void
	{
		$path = dirname(__DIR__, 4) . '/src/ORM/Representation/Schema/Query/QueryRepresentationSourceResolver.php';
		$contents = (string) file_get_contents($path);

		self::assertStringNotContainsString('AliasedExpression', $contents, $path);
		self::assertStringNotContainsString('SelectionItem', $contents, $path);
		self::assertStringNotContainsString('RepresentationFieldSchema', $contents, $path);
	}

	public function testRepresentationFieldSchemasAreCreatedBySchemaAssembler(): void
	{
		$root = dirname(__DIR__, 4);

		foreach ([
			$root . '/src/ORM/Representation/Schema/Query/QueryRepresentationSelectionNormalizer.php',
			$root . '/src/ORM/Representation/Schema/Shape/RepresentationFieldShape.php',
			$root . '/src/ORM/Representation/Schema/Query/QueryRepresentationSourceResolver.php',
			$root . '/src/ORM/Representation/Schema/Query/QueryRepresentationSchemaCompiler.php',
		] as $path) {
			self::assertStringNotContainsString('new RepresentationFieldSchema', (string) file_get_contents($path), $path);
		}

		$assembler = (string) file_get_contents($root . '/src/ORM/Representation/Schema/Shape/RepresentationSchemaAssembler.php');
		self::assertStringContainsString('new RepresentationFieldSchema', $assembler);
	}

	public function testSessionDoesNotExposeDurableProjectionStores(): void
	{
		$methods = get_class_methods(Session::class);

		foreach ([
			'getProjectionSources',
			'getProjectionRecords',
			'getProjectionRelations',
			'trackProjectionSource',
			'trackProjectionRelation',
			'projection',
		] as $method) {
			self::assertNotContains($method, $methods);
		}

		self::assertContains('getRecords', $methods);
		self::assertContains('getRepresentations', $methods);
		self::assertContains('getRelations', $methods);
		self::assertContains('update', $methods);
		self::assertContains('schemaOf', $methods);
		self::assertContains('detach', $methods);
	}

	public function testSelectQueryProjectionReturnsCompiledRepresentationSchema(): void
	{
		$users = $this->users();
		$query = new SelectQuery($users);
		$query->select($query->name->as('display_name'));

		$schema = $query->projection();

		self::assertInstanceOf(RepresentationSchema::class, $schema);
		self::assertSame('users', $schema->getCollectionName());
		self::assertTrue($schema->hasField('display_name'));
		self::assertFalse($schema->hasField('name'));
		self::assertSame('name', $schema->getField('display_name')->getFieldName());
		self::assertTrue($schema->hasField('id'));
		self::assertTrue($schema->getField('id')->isReadOnly());
	}

	public function testSelectQueryProjectionMatchesQueryRepresentationSchemaCompiler(): void
	{
		$users = $this->users();
		$query = new SelectQuery($users);
		$query->select($query->id, $query->name);

		$viaProjection = $query->projection();
		$viaCompiler = (new QueryRepresentationSchemaCompiler())->compileSchema($query);

		self::assertSame($viaCompiler->getPaths(), $viaProjection->getPaths());
		self::assertSame('name', $viaProjection->getField('name')->getFieldName());
	}

	public function testQueryProjectionUsesAssemblerPublicPaths(): void
	{
		$users = $this->users();
		$normalizer = new QueryRepresentationSelectionNormalizer();
		$assembler = new RepresentationSchemaAssembler();

		$query = new SelectQuery($users);
		$query->select($query->name->as('display_name'));
		$querySchema = $assembler->assemble(
			$normalizer->normalizeSelections($query->getSelections()->getExplicit()),
			new QueryRepresentationSourceResolver($query),
			$users,
		);

		self::assertSame(['display_name'], $querySchema->getPaths());
		self::assertSame('name', $querySchema->getField('display_name')->getFieldName());
		self::assertSame('users', $querySchema->getField('display_name')->getCollectionName());
	}

	public function testSessionUpdateFromSyncTracksDtoAsExisting(): void
	{
		$users = $this->users();
		$session = new Session(new RecordingCommandExecutor());
		$query = new SelectQuery($users);
		$query->select($query->id, $query->name);
		$map = $query->projection();
		$dto = $this->representation(['id' => 10, 'name' => 'Ada']);

		$builder = $session->update($dto, $map);
		$result = $session->sync($dto);

		self::assertSame($dto, $builder->getRepresentation());
		self::assertSame($map, $builder->getIntent()->getSchema());
		self::assertSame($users, $map->getCollection());
		// DTO values are applied as dirty during flat adopt; sync may be a no-op.
		self::assertFalse($result->hasChanges());

		$tracked = $session->getRepresentations()->get($dto);
		self::assertInstanceOf(RepresentationState::class, $tracked);
		$record = $tracked->getSingleRecord();
		self::assertInstanceOf(RecordState::class, $record);
		self::assertTrue($record->isDirty());
		self::assertSame(['id' => 10], $record->getKey()?->getValues());
		self::assertSame('Ada', $record->getValue('name'));
	}

	public function testSchemaOfReturnsTrackedRepresentationSchema(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$users = $this->users();
		$schema = $this->userSchemaWithId();
		$dto = $this->representation(['id' => 10, 'name' => 'Ada']);
		$record = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Ada']);
		$session->getRecords()->add($record);
		$this->adoptWithRecord($session, $dto, $schema, $record);

		self::assertSame($schema, $session->schemaOf($dto));
		self::assertSame(['id', 'name'], $session->schemaOf($dto)->getPaths());
	}

	public function testSchemaOfThrowsWhenRepresentationIsNotTracked(): void
	{
		$session = new Session(new RecordingCommandExecutor());

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('not tracked');

		$session->schemaOf($this->representation(['name' => 'Ada']));
	}

	public function testDetachWithIdentifyUnlinksM2MTargetWithoutDeletingIt(): void
	{
		[$users, $tags] = $this->usersWithTags();
		$session = new Session(new RecordingCommandExecutor());
		$ownerRecord = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Ada']);
		$session->getRecords()->add($ownerRecord);
		$owner = $this->representation(['id' => 10, 'name' => 'Ada']);
		$this->adoptWithRecord($session, $owner, $this->ownerSchemaWithTags($users, $tags), $ownerRecord);

		$tag = $session->identify($tags, ['id' => 3]);
		$session->detach($tag, $owner, 'tags');

		$relation = $session->getRelations()->get($ownerRecord, 'tags');
		self::assertInstanceOf(ToManyRelationState::class, $relation);
		$removed = $relation->getRemoved();
		self::assertCount(1, $removed);
		$tagRecord = $session->getRepresentations()->get($tag)->getSingleRecord();
		self::assertInstanceOf(RecordState::class, $tagRecord);
		self::assertSame($tagRecord, $removed[0]);
		self::assertTrue($session->getRepresentations()->has($tag));
		self::assertFalse($tagRecord->isRemoved());
		self::assertTrue($tagRecord->isClean());
	}

	/**
	 * @return array{0: CollectionInterface, 1: CollectionInterface, 2: CollectionInterface}
	 */
	private function usersWithTags(): array
	{
		$registry = new Registry();
		$registry->collection('tags')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('label', 'string')->end()
			->end();
		$registry->collection('user_tag')
			->field('user_id', 'int')->end()
			->field('tag_id', 'int')->end()
			->end();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		$relation = $users->relation('tags', M2MRelation::class)
			->collection('tags')
			->innerKey('id')
			->outerKey('id')
			->through('user_tag')
				->innerKey('user_id')
				->outerKey('tag_id')
				->end();

		self::assertInstanceOf(M2MRelation::class, $relation);
		$tags = $registry->getCollection('tags');
		$through = $registry->getCollection('user_tag');
		self::assertInstanceOf(CollectionInterface::class, $tags);
		self::assertInstanceOf(CollectionInterface::class, $through);

		return [$users, $tags, $through];
	}

	private function ownerSchemaWithTags(CollectionInterface $users, CollectionInterface $tags): RepresentationSchema
	{
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('id', $users, 'id'));
		$schema->addField(new RepresentationFieldSchema('name', $users, 'name'));
		$tagSchema = new RepresentationSchema($tags);
		$tagSchema->addField(new RepresentationFieldSchema('id', $tags, 'id'));
		$schema->addRelation(new RepresentationRelationSchema(
			'tags',
			$users,
			'tags',
			$tagSchema,
			false,
		));

		return $schema;
	}
}
