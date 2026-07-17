<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\IntentBuilder;
use ON\Data\ORM\Persistence\CommandPlanner;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\UpdateCommand;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\RepresentationRelationSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Session;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\ORM\Support\OrmFixture;
use Tests\ON\Data\Smoke\Support\SqliteMemoryHarness;
use Tests\ON\Data\Support\RecordingCommandExecutor;
use Throwable;

final class GraphSyncExistingIntentTest extends TestCase
{
	use OrmFixture;

	public function testUntrackedRelatedObjectWithoutPrimaryKeyIsAdoptedAsNew(): void
	{
		[$users, $posts] = $this->usersWithPostsRelation();
		$session = new Session(new RecordingCommandExecutor());
		$post = $this->representation(['title' => 'Draft']);
		$owner = $this->representation(['id' => 10, 'name' => 'Owner', 'posts' => [$post]]);

		$session->sync($owner, $this->ownerSchemaWithPosts($users, $posts));

		$record = $session->getRepresentations()->get($post)->getSingleRecord();
		self::assertInstanceOf(RecordState::class, $record);
		self::assertTrue($record->isNew());
		self::assertFalse($record->hasKey());
	}

	public function testUntrackedRelatedObjectWithAppAssignedSinglePrimaryKeyIsAdoptedAsNewAndPlannedAsInsert(): void
	{
		[$users, $posts] = $this->usersWithPostsRelation();
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$post = $this->representation(['id' => 99, 'title' => 'Draft', 'user_id' => null]);
		$owner = $this->representation(['id' => 10, 'name' => 'Owner', 'posts' => [$post]]);
		$session->update($owner);

		$session->sync($owner, $this->ownerSchemaWithPosts($users, $posts));
		$session->flush();

		$commands = $executor->getCommands();
		self::assertCount(2, $commands);
		$ownerCommand = $commands[0];
		self::assertInstanceOf(UpdateCommand::class, $ownerCommand);
		self::assertSame(['id' => 10], $ownerCommand->getIdentity());
		self::assertSame(['name' => 'Owner'], $ownerCommand->getChanges());
		$postCommand = $commands[1];
		self::assertInstanceOf(InsertCommand::class, $postCommand);
		self::assertSame(['id' => 99, 'title' => 'Draft', 'user_id' => 10], $postCommand->getValues());
	}

	public function testUntrackedRelatedObjectWithAppAssignedCompositePrimaryKeyIsAdoptedAsNewAndPlannedAsInsert(): void
	{
		[$users, $posts] = $this->usersWithCompositePostsRelation();
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$post = $this->representation(['tenant_ref' => 7, 'user_ref' => 10, 'title' => 'Draft']);
		$owner = $this->representation(['tenant_id' => 7, 'user_id' => 10, 'posts' => [$post]]);
		$session->update($owner);

		$session->sync($owner, $this->compositeOwnerSchemaWithPosts($users, $posts));

		$record = $session->getRepresentations()->get($post)->getSingleRecord();
		self::assertInstanceOf(RecordState::class, $record);
		self::assertTrue($record->isNew());
		self::assertFalse($record->hasKey());

		$session->flush();
		self::assertSame(['tenant_ref' => 7, 'user_ref' => 10, 'title' => 'Draft'], $record->getValues());

		$inserts = array_values(array_filter(
			$executor->getCommands(),
			static fn ($command): bool => $command instanceof InsertCommand,
		));
		self::assertCount(1, $inserts);
		self::assertSame(['tenant_ref' => 7, 'user_ref' => 10, 'title' => 'Draft'], $inserts[0]->getValues());
	}

	public function testExistingMarkedRelatedObjectIsAdoptedAsManagedAndNotPlannedAsInsert(): void
	{
		[$users, $posts] = $this->usersWithPostsRelation();
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$post = $this->representation(['id' => 5, 'title' => 'Existing', 'user_id' => 10]);
		$owner = $this->representation(['id' => 10, 'name' => 'Owner', 'posts' => [$post]]);
		$session->update($owner);
		$intent = $session->update($post);

		self::assertInstanceOf(IntentBuilder::class, $intent);
		self::assertSame($post, $intent->getRepresentation());

		$session->sync($owner, $this->ownerSchemaWithPosts($users, $posts));
		$session->flush();

		$record = $session->getRepresentations()->get($post)->getSingleRecord();
		self::assertInstanceOf(RecordState::class, $record);
		self::assertTrue($record->isClean());
		self::assertSame(5, $record->getKey()?->getFieldValue('id'));

		$commands = $executor->getCommands();
		self::assertNotEmpty($commands);
		foreach ($commands as $command) {
			self::assertNotInstanceOf(InsertCommand::class, $command);
			self::assertInstanceOf(UpdateCommand::class, $command);
		}
	}

	public function testIdentifyResultAddedToRelationRemainsExistingCleanReferenceAndIsNotPlannedAsInsert(): void
	{
		[$users, $posts] = $this->usersWithPostsRelation();
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$owner = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$session->getRecords()->add($owner);
		$post = $session->identify($posts, ['id' => 5], schema: $this->postKeyOnlySchemaFor($posts));
		$ownerRepresentation = $this->representation(['id' => 10, 'name' => 'Owner', 'posts' => [$post]]);
		$this->adoptWithRecord($session, $ownerRepresentation, $this->ownerSchemaWithPostsKeyOnlyChild($users, $posts), $owner);

		$session->sync($ownerRepresentation);
		$session->flush();

		$record = $session->getRepresentations()->get($post)->getSingleRecord();
		self::assertInstanceOf(RecordState::class, $record);
		self::assertTrue($record->isClean());

		foreach ($executor->getCommands() as $command) {
			self::assertNotInstanceOf(InsertCommand::class, $command);
		}
	}

	public function testAlreadyTrackedRelatedObjectKeepsLifecycleAndIsNotReclassifiedByPrimaryKeyPresence(): void
	{
		[$users, $posts] = $this->usersWithPostsRelation();
		$session = new Session(new RecordingCommandExecutor());
		$owner = RecordState::clean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$session->getRecords()->add($owner);
		$child = RecordState::new($posts, ['id' => 5, 'title' => 'Draft', 'user_id' => null]);
		$session->getRecords()->add($child);
		$post = $this->representation(['id' => 5, 'title' => 'Draft', 'user_id' => null]);
		$ownerRepresentation = $this->representation(['id' => 10, 'name' => 'Owner', 'posts' => [$post]]);
		$this->adoptWithRecord($session, $ownerRepresentation, $this->ownerSchemaWithPosts($users, $posts), $owner);
		$this->adoptWithRecord($session, $post, $this->postSchemaWithIdFor($posts), $child);

		$session->sync($ownerRepresentation);

		self::assertSame($child, $session->getRepresentations()->get($post)->getSingleRecord());
		self::assertTrue($child->isNew());
		self::assertFalse($child->hasKey());
	}

	public function testDuplicateAppAssignedKeyOnNewObjectIsPlannedAsInsertNotUpdate(): void
	{
		[$users, $posts] = $this->usersWithPostsRelation();
		$session = new Session(new RecordingCommandExecutor());
		$post = $this->representation(['id' => 99, 'title' => 'Draft', 'user_id' => null]);
		$owner = $this->representation(['id' => 10, 'name' => 'Owner', 'posts' => [$post]]);

		$session->sync($owner, $this->ownerSchemaWithPosts($users, $posts));

		$record = $session->getRepresentations()->get($post)->getSingleRecord();
		self::assertInstanceOf(RecordState::class, $record);

		$command = (new CommandPlanner())->plan($record);
		self::assertInstanceOf(InsertCommand::class, $command);
		self::assertNotInstanceOf(UpdateCommand::class, $command);
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testDuplicateAppAssignedKeyFailsAtDatabaseConstraintDuringFlush(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE app_posts (post_id INTEGER PRIMARY KEY, title TEXT, user_id INTEGER)');
		$harness->exec('INSERT INTO app_posts (post_id, title, user_id) VALUES (99, "Existing", 10)');

		$registry = new Registry();
		$users = $registry
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		$posts = $registry
			->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->column('post_id')->end()
			->field('title', 'string')->end()
			->field('user_id', 'int')->end();
		$users->hasMany('posts', 'posts')->innerKey('id')->outerKey('user_id');

		$session = new Session($harness->commandExecutor);
		$post = $this->representation(['id' => 99, 'title' => 'Duplicate', 'user_id' => null]);
		$owner = $this->representation(['id' => 10, 'name' => 'Owner', 'posts' => [$post]]);
		$session->update($owner);

		$session->sync($owner, $this->ownerSchemaWithPosts($users, $posts));

		$this->expectException(Throwable::class);

		$session->flush();
	}

	public function testExistingMarkerWithoutReadablePrimaryKeyThrowsDuringAdoption(): void
	{
		[$users, $posts] = $this->usersWithPostsRelation();
		$session = new Session(new RecordingCommandExecutor());
		$post = $this->representation(['title' => 'Existing']);
		$session->update($post);
		$owner = $this->representation(['id' => 10, 'name' => 'Owner', 'posts' => [$post]]);
		$session->update($owner);

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('Cannot adopt update representation');

		$session->sync($owner, $this->ownerSchemaWithPosts($users, $posts));
	}

	/**
	 * @return array{0: CollectionInterface, 1: CollectionInterface}
	 */
	private function usersWithPostsRelation(): array
	{
		$registry = new Registry();
		$registry->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end()
			->field('user_id', 'int')->end()
			->end();
		$posts = $registry->getCollection('posts');
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		$users->hasMany('posts', 'posts')->innerKey('id')->outerKey('user_id');

		return [$users, $posts];
	}

	/**
	 * @return array{0: CollectionInterface, 1: CollectionInterface}
	 */
	private function usersWithCompositePostsRelation(): array
	{
		$registry = new Registry();
		$posts = $registry->collection('posts')
			->primaryKey('tenant_ref', 'user_ref')
			->field('tenant_ref', 'int')->end()
			->field('user_ref', 'int')->end()
			->field('title', 'string')->end();
		$users = $registry->collection('users')
			->primaryKey('tenant_id', 'user_id')
			->field('tenant_id', 'int')->end()
			->field('user_id', 'int')->end();
		$users->hasMany('posts', 'posts')
			->innerKey(['tenant_id', 'user_id'])
			->outerKey(['tenant_ref', 'user_ref']);

		return [$users, $posts];
	}

	private function compositeOwnerSchemaWithPosts(CollectionInterface $users, CollectionInterface $posts): RepresentationSchema
	{
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('tenant_id', $users, 'tenant_id'));
		$schema->addField(new RepresentationFieldSchema('user_id', $users, 'user_id'));
		$postSchema = new RepresentationSchema($posts);
		$postSchema->addField(new RepresentationFieldSchema('tenant_ref', $posts, 'tenant_ref'));
		$postSchema->addField(new RepresentationFieldSchema('user_ref', $posts, 'user_ref'));
		$postSchema->addField(new RepresentationFieldSchema('title', $posts, 'title'));
		$schema->addRelation(new RepresentationRelationSchema(
			'posts',
			$users,
			'posts',
			$postSchema,
			false
		));

		return $schema;
	}

	private function ownerSchemaWithPosts(CollectionInterface $users, CollectionInterface $posts): RepresentationSchema
	{
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('id', $users, 'id'));
		$schema->addField(new RepresentationFieldSchema('name', $users, 'name'));
		$schema->addRelation(new RepresentationRelationSchema(
			'posts',
			$users,
			'posts',
			$this->postSchemaWithIdFor($posts),
			false
		));

		return $schema;
	}

	private function postSchemaWithIdFor(CollectionInterface $posts): RepresentationSchema
	{
		$schema = new RepresentationSchema($posts);
		$schema->addField(new RepresentationFieldSchema('id', $posts, 'id'));
		$schema->addField(new RepresentationFieldSchema('title', $posts, 'title'));
		$schema->addField(new RepresentationFieldSchema('user_id', $posts, 'user_id'));

		return $schema;
	}

	private function postKeyOnlySchemaFor(CollectionInterface $posts): RepresentationSchema
	{
		$schema = new RepresentationSchema($posts);
		$schema->addField(new RepresentationFieldSchema('id', $posts, 'id'));

		return $schema;
	}

	private function ownerSchemaWithPostsKeyOnlyChild(CollectionInterface $users, CollectionInterface $posts): RepresentationSchema
	{
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('id', $users, 'id'));
		$schema->addField(new RepresentationFieldSchema('name', $users, 'name'));
		$schema->addRelation(new RepresentationRelationSchema(
			'posts',
			$users,
			'posts',
			$this->postKeyOnlySchemaFor($posts),
			false
		));

		return $schema;
	}
}
