<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Persistence\CommandPlanner;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\UpdateCommand;
use ON\Data\ORM\Session;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use ON\Data\ORM\Sync\ExistingIntent;
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

		$session->sync($owner, $this->ownerBindingWithPosts($users, $posts));

		$record = $session->getRecords()->getFromRepresentation($session->getRepresentations()->get($post));
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

		$session->sync($owner, $this->ownerBindingWithPosts($users, $posts));
		$session->flush();

		self::assertCount(1, $executor->getCommands());
		$postCommand = $executor->getCommands()[0];
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

		$session->sync($owner, $this->compositeOwnerBindingWithPosts($users, $posts));

		$record = $session->getRecords()->getFromRepresentation($session->getRepresentations()->get($post));
		self::assertInstanceOf(RecordState::class, $record);
		self::assertTrue($record->isNew());
		self::assertFalse($record->hasKey());

		$session->flush();
		self::assertSame(['tenant_ref' => 7, 'user_ref' => 10, 'title' => 'Draft'], $record->getValues());

		$postCommand = $executor->getCommands()[0];
		self::assertInstanceOf(InsertCommand::class, $postCommand);
		self::assertSame(['tenant_ref' => 7, 'user_ref' => 10, 'title' => 'Draft'], $postCommand->getValues());
	}

	public function testExistingMarkedRelatedObjectIsAdoptedAsManagedAndNotPlannedAsInsert(): void
	{
		[$users, $posts] = $this->usersWithPostsRelation();
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$post = $this->representation(['id' => 5, 'title' => 'Existing', 'user_id' => 10]);
		$owner = $this->representation(['id' => 10, 'name' => 'Owner', 'posts' => [$post]]);
		$intent = $session->existing($post);

		self::assertInstanceOf(ExistingIntent::class, $intent);
		self::assertSame($post, $intent->getRepresentation());

		$session->sync($owner, $this->ownerBindingWithPosts($users, $posts));
		$session->flush();

		$record = $session->getRecords()->getFromRepresentation($session->getRepresentations()->get($post));
		self::assertInstanceOf(RecordState::class, $record);
		self::assertTrue($record->isClean());
		self::assertSame(5, $record->getKey()?->getFieldValue('id'));

		foreach ($executor->getCommands() as $command) {
			self::assertNotInstanceOf(InsertCommand::class, $command);
		}
	}

	public function testIdentifyResultAddedToRelationRemainsExistingCleanReferenceAndIsNotPlannedAsInsert(): void
	{
		[$users, $posts] = $this->usersWithPostsRelation();
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$owner = $session->trackClean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$post = $session->identify($posts, ['id' => 5], binding: $this->postKeyOnlyBindingFor($posts));
		$ownerRepresentation = $this->representation(['id' => 10, 'name' => 'Owner', 'posts' => [$post]]);
		$session->adopt($ownerRepresentation, $this->ownerBindingWithPostsKeyOnlyChild($users, $posts), $owner);

		$session->sync($ownerRepresentation);
		$session->flush();

		$record = $session->getRecords()->getFromRepresentation($session->getRepresentations()->get($post));
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
		$owner = $session->trackClean($users->getKey(10), ['id' => 10, 'name' => 'Owner']);
		$child = $session->trackNew($posts, ['id' => 5, 'title' => 'Draft', 'user_id' => null]);
		$post = $this->representation(['id' => 5, 'title' => 'Draft', 'user_id' => null]);
		$ownerRepresentation = $this->representation(['id' => 10, 'name' => 'Owner', 'posts' => [$post]]);
		$session->adopt($ownerRepresentation, $this->ownerBindingWithPosts($users, $posts), $owner);
		$session->adopt($post, $this->postBindingWithIdFor($posts), $child);

		$session->sync($ownerRepresentation);

		self::assertSame($child, $session->getRecords()->getFromRepresentation($session->getRepresentations()->get($post)));
		self::assertTrue($child->isNew());
		self::assertFalse($child->hasKey());
	}

	public function testDuplicateAppAssignedKeyOnNewObjectIsPlannedAsInsertNotUpdate(): void
	{
		[$users, $posts] = $this->usersWithPostsRelation();
		$session = new Session(new RecordingCommandExecutor());
		$post = $this->representation(['id' => 99, 'title' => 'Draft', 'user_id' => null]);
		$owner = $this->representation(['id' => 10, 'name' => 'Owner', 'posts' => [$post]]);

		$session->sync($owner, $this->ownerBindingWithPosts($users, $posts));

		$record = $session->getRecords()->getFromRepresentation($session->getRepresentations()->get($post));
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

		$session->sync($owner, $this->ownerBindingWithPosts($users, $posts));

		$this->expectException(Throwable::class);

		$session->flush();
	}

	public function testExistingMarkerWithoutReadablePrimaryKeyThrowsDuringAdoption(): void
	{
		[$users, $posts] = $this->usersWithPostsRelation();
		$session = new Session(new RecordingCommandExecutor());
		$post = $this->representation(['title' => 'Existing']);
		$session->existing($post);
		$owner = $this->representation(['id' => 10, 'name' => 'Owner', 'posts' => [$post]]);

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('Cannot adopt existing representation');

		$session->sync($owner, $this->ownerBindingWithPosts($users, $posts));
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

	private function compositeOwnerBindingWithPosts(CollectionInterface $users, CollectionInterface $posts): RepresentationBinding
	{
		$binding = new RepresentationBinding($users);
		$binding->addField(new RepresentationFieldBinding('tenant_id', $users, 'tenant_id'));
		$binding->addField(new RepresentationFieldBinding('user_id', $users, 'user_id'));
		$postBinding = new RepresentationBinding($posts);
		$postBinding->addField(new RepresentationFieldBinding('tenant_ref', $posts, 'tenant_ref'));
		$postBinding->addField(new RepresentationFieldBinding('user_ref', $posts, 'user_ref'));
		$postBinding->addField(new RepresentationFieldBinding('title', $posts, 'title'));
		$binding->addRelation(new RepresentationRelationBinding(
			'posts',
			$users, 'posts',
			$postBinding,
			false
		));

		return $binding;
	}

	private function ownerBindingWithPosts(CollectionInterface $users, CollectionInterface $posts): RepresentationBinding
	{
		$binding = new RepresentationBinding($users);
		$binding->addField(new RepresentationFieldBinding('id', $users, 'id'));
		$binding->addField(new RepresentationFieldBinding('name', $users, 'name'));
		$binding->addRelation(new RepresentationRelationBinding(
			'posts',
			$users, 'posts',
			$this->postBindingWithIdFor($posts),
			false
		));

		return $binding;
	}

	private function postBindingWithIdFor(CollectionInterface $posts): RepresentationBinding
	{
		$binding = new RepresentationBinding($posts);
		$binding->addField(new RepresentationFieldBinding('id', $posts, 'id'));
		$binding->addField(new RepresentationFieldBinding('title', $posts, 'title'));
		$binding->addField(new RepresentationFieldBinding('user_id', $posts, 'user_id'));

		return $binding;
	}

	private function postKeyOnlyBindingFor(CollectionInterface $posts): RepresentationBinding
	{
		$binding = new RepresentationBinding($posts);
		$binding->addField(new RepresentationFieldBinding('id', $posts, 'id'));

		return $binding;
	}

	private function ownerBindingWithPostsKeyOnlyChild(CollectionInterface $users, CollectionInterface $posts): RepresentationBinding
	{
		$binding = new RepresentationBinding($users);
		$binding->addField(new RepresentationFieldBinding('id', $users, 'id'));
		$binding->addField(new RepresentationFieldBinding('name', $users, 'name'));
		$binding->addRelation(new RepresentationRelationBinding(
			'posts',
			$users, 'posts',
			$this->postKeyOnlyBindingFor($posts),
			false
		));

		return $binding;
	}
}
