<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\State\RepresentationStateStore;
use ON\Data\ORM\Session;
use ON\Data\Query\SelectQuery;
use ON\Data\Schema\RepresentationFieldSchema;
use ON\Data\Schema\RepresentationRelationSchema;
use ON\Data\Schema\RepresentationSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\ORM\Support\OrmFixture;
use Tests\ON\Data\Support\RecordingCommandExecutor;

final class SessionGraphAdoptionTest extends TestCase
{
	use OrmFixture;

	#[DataProvider('childWritePolicies')]
	public function testChildIntentPreservesIndependentFlatSourcePolicy(bool $createChild, ?string $flatOperation): void
	{
		$users = $this->users();
		$users->hasMany('reports', 'users')->innerKey('id')->outerKey('manager_id');
		$users->belongsTo('manager', 'users')->innerKey('manager_id')->outerKey('id');
		$childSchema = new RepresentationSchema($users);
		$childSchema->addField(new RepresentationFieldSchema('id', $users, 'id'));
		$childSchema->addField(new RepresentationFieldSchema('name', $users, 'name'));
		$childSchema->addField(new RepresentationFieldSchema('managerId', $users, 'id', sourcePath: ['manager']));
		$childSchema->addField(new RepresentationFieldSchema('managerName', $users, 'name', sourcePath: ['manager']));
		$ownerSchema = new RepresentationSchema($users);
		$ownerSchema->addField(new RepresentationFieldSchema('id', $users, 'id'));
		$ownerSchema->addRelation(new RepresentationRelationSchema('reports', $users, 'reports', $childSchema));
		$child = (object) ['id' => 20, 'name' => 'Child', 'managerId' => 30, 'managerName' => 'Manager'];
		$owner = (object) ['id' => 10, 'reports' => [$child]];
		$session = new Session(new RecordingCommandExecutor());
		$intent = $createChild ? $session->create($child) : $session->update($child);
		if ($flatOperation === 'update') {
			$intent->update('manager');
		} elseif ($flatOperation === 'create') {
			$intent->create('manager');
		}

		$session->sync($owner, $ownerSchema);

		$state = $session->getRepresentations()->get($child);
		self::assertInstanceOf(RepresentationState::class, $state);
		$childRecord = $state->requireRootRecord();
		$managerRecord = $state->getFieldItem('managerName')->getRecord();
		self::assertSame($createChild, $childRecord->isNew());
		self::assertSame(! $createChild, $childRecord->isDirty());
		self::assertSame($flatOperation !== 'update', $managerRecord->isNew());
		self::assertSame($flatOperation === 'update', $managerRecord->isDirty());
		self::assertSame('Child', $childRecord->getValue('name'));
		self::assertSame('Manager', $managerRecord->getValue('name'));
		self::assertFalse($session->getContext()->getIntents()->has($child));
	}

	/** @return iterable<string, array{bool, ?string}> */
	public static function childWritePolicies(): iterable
	{
		yield 'update child, default flat create' => [false, null];
		yield 'update child, explicit flat update' => [false, 'update'];
		yield 'update child, explicit flat create' => [false, 'create'];
		yield 'create child, explicit flat update' => [true, 'update'];
	}

	public function testStagedCyclicGraphBindsEachObjectOnlyOnceWithExplicitSchemas(): void
	{
		$users = $this->users();
		$users->belongsTo('manager', 'users')->innerKey('manager_id')->outerKey('id')->nullable(true);
		$query = new SelectQuery($users);
		$query->select($query->name);
		$query->manager->select('name');
		$schema = $query->projection();
		$first = (object) ['name' => 'First'];
		$second = (object) ['name' => 'Second'];
		$first->manager = $second;
		$second->manager = $first;
		$session = new Session(new RecordingCommandExecutor());
		$session->create($first, $schema);
		$session->create($second, $schema);

		$session->sync();

		self::assertCount(2, $session->getRecords()->getAll());
		self::assertCount(2, $session->getRepresentations()->getAll());
		self::assertSame('First', $session->getRepresentations()->get($first)->requireRootRecord()->getValue('name'));
		self::assertSame('Second', $session->getRepresentations()->get($second)->requireRootRecord()->getValue('name'));
	}

	public function testAdoptRootWithNoRelationSchemasReturnsEmptyResult(): void
	{
		$root = $this->representation(['name' => 'Root']);
		$representations = $this->representations($this->tracked($root, $this->userSchemaFor(RecordState::new($this->users()))));

		$this->session($representations)->sync($root);

		self::assertCount(1, $representations->getAll());
	}

	public function testUntrackedRootWithSchemaIsAdopted(): void
	{
		$root = $this->representation(['name' => 'Root']);
		$records = new RecordStateStore();
		$representations = new RepresentationStateStore();
		$session = $this->session($representations, $records);

		$session->sync($root, $this->userSchema());

		$tracked = $representations->get($root);
		self::assertInstanceOf(RepresentationState::class, $tracked);
		self::assertSame('Root', $tracked->getSingleRecord()?->getValue('name'));
		self::assertTrue($tracked->getSingleRecord()?->isNew());
	}

	public function testUntrackedRootWithCompleteKeyIsAdoptedAsNewWithoutExistingIntent(): void
	{
		$root = $this->representation(['id' => 10, 'name' => 'Root']);
		$records = new RecordStateStore();
		$reps = new RepresentationStateStore();

		$this->session($reps, $records)->sync($root, $this->userSchemaWithId());

		$tracked = $reps->get($root);
		self::assertInstanceOf(RepresentationState::class, $tracked);
		$record = $tracked->getSingleRecord();
		self::assertInstanceOf(RecordState::class, $record);
		self::assertTrue($record->isNew());
		self::assertFalse($record->hasKey());
		self::assertSame(10, $record->getValue('id'));
		self::assertSame('Root', $record->getValue('name'));
	}

	public function testUntrackedRootWithCompleteKeyIsPatchedWhenMarkedExisting(): void
	{
		$root = $this->representation(['id' => 10, 'name' => 'Root']);
		$records = new RecordStateStore();
		$reps = new RepresentationStateStore();
		$session = $this->session($reps, $records);
		$session->update($root);

		$session->sync($root, $this->userSchemaWithId());

		$tracked = $reps->get($root);
		self::assertInstanceOf(RepresentationState::class, $tracked);
		$record = $tracked->getSingleRecord();
		self::assertInstanceOf(RecordState::class, $record);
		self::assertTrue($record->isDirty());
		self::assertSame(10, $record->getKey()?->getFieldValue('id'));
		self::assertSame('Root', $record->getValue('name'));
		self::assertSame(['name'], $record->getDirtyFields());
	}

	public function testUntrackedRootWithoutCompleteKeyIsAdoptedAsNew(): void
	{
		$root = $this->representation(['id' => null, 'name' => 'Root']);
		$records = new RecordStateStore();
		$representations = new RepresentationStateStore();

		$this->session($representations, $records)->sync($root, $this->userSchemaWithId());

		$tracked = $representations->get($root);
		self::assertInstanceOf(RepresentationState::class, $tracked);
		$record = $tracked->getSingleRecord();
		self::assertInstanceOf(RecordState::class, $record);
		self::assertTrue($record->isNew());
	}

	public function testUntrackedRootCreateKeepsNonKeyNullAndOmitsNullPrimaryKey(): void
	{
		$root = $this->representation(['id' => null, 'name' => null]);
		$records = new RecordStateStore();
		$reps = new RepresentationStateStore();

		$this->session($reps, $records)->sync($root, $this->userSchemaWithId());

		$record = $reps->get($root)?->getSingleRecord();
		self::assertInstanceOf(RecordState::class, $record);
		self::assertTrue($record->isNew());
		self::assertFalse(array_key_exists('id', $record->getValues()));
		self::assertArrayHasKey('name', $record->getValues());
		self::assertNull($record->getValue('name'));
	}

	public function testUntrackedRootPatchKeepsNonKeyNull(): void
	{
		$root = $this->representation(['id' => 10, 'name' => null]);
		$records = new RecordStateStore();
		$reps = new RepresentationStateStore();
		$session = $this->session($reps, $records);
		$session->update($root);

		$session->sync($root, $this->userSchemaWithId());

		$record = $reps->get($root)?->getSingleRecord();
		self::assertInstanceOf(RecordState::class, $record);
		self::assertTrue($record->isDirty());
		self::assertNull($record->getValue('name'));
		self::assertSame(['name'], $record->getDirtyFields());
	}

	public function testTwoPlaceAliasesOfTheSameFieldAdoptWhenTheyAgree(): void
	{
		$root = $this->representation(['id' => 10, 'name' => 'Ada', 'displayName' => 'Ada']);
		$records = new RecordStateStore();
		$reps = new RepresentationStateStore();

		$this->session($reps, $records)->sync($root, $this->userSchemaWithNameAlias());

		$record = $reps->get($root)?->getSingleRecord();
		self::assertInstanceOf(RecordState::class, $record);
		self::assertTrue($record->isNew());
		self::assertSame('Ada', $record->getValue('name'));
	}

	public function testTwoWritablePlaceAliasesOfTheSameFieldThrowWhenTheyDisagree(): void
	{
		$root = $this->representation(['id' => 10, 'name' => 'Ada', 'displayName' => 'Other']);

		$this->expectException(StateException::class);
		$this->expectExceptionMessage("disagree on field 'name'");

		$this->session(new RepresentationStateStore(), new RecordStateStore())
			->sync($root, $this->userSchemaWithNameAlias());
	}

	public function testReadOnlyPlaceAliasDoesNotOverrideOrConflictWithWritableField(): void
	{
		$root = $this->representation(['id' => 10, 'name' => 'Ada', 'upperName' => 'ADA']);
		$records = new RecordStateStore();
		$reps = new RepresentationStateStore();

		$this->session($reps, $records)->sync($root, $this->userSchemaWithReadOnlyNameAlias());

		$record = $reps->get($root)?->getSingleRecord();
		self::assertInstanceOf(RecordState::class, $record);
		self::assertSame('Ada', $record->getValue('name'));
	}

	public function testReadOnlyPlaceAliasFillsFieldWhenNoWritablePlaceIsPresent(): void
	{
		$root = $this->representation(['id' => 10, 'upperName' => 'ADA']);
		$records = new RecordStateStore();
		$reps = new RepresentationStateStore();

		$this->session($reps, $records)->sync($root, $this->userSchemaWithReadOnlyNameAlias());

		$record = $reps->get($root)?->getSingleRecord();
		self::assertInstanceOf(RecordState::class, $record);
		self::assertSame('ADA', $record->getValue('name'));
	}

	public function testMissingBindingPlaceStillAdoptsAPresentAliasOfTheSameField(): void
	{
		$root = new stdClass();
		$root->id = 10;
		$root->displayName = 'Ada';
		$records = new RecordStateStore();
		$reps = new RepresentationStateStore();

		$this->session($reps, $records)->sync($root, $this->userSchemaWithNameAlias());

		$record = $reps->get($root)?->getSingleRecord();
		self::assertInstanceOf(RecordState::class, $record);
		self::assertSame('Ada', $record->getValue('name'));
	}

	public function testUntrackedRootWithoutSchemaThrows(): void
	{
		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('without a root RepresentationSchema');

		$this->session(new RepresentationStateStore())->sync(new stdClass());
	}

	public function testManyRelationWithNullValueAdoptsNothing(): void
	{
		$root = $this->representation(['posts' => null]);
		$representations = $this->representations($this->trackedRootWithPosts($root));

		$this->session($representations)->sync($root);

		self::assertCount(1, $representations->getAll());
	}

	public function testManyRelationWithIterableObjectsAdoptsUntrackedItems(): void
	{
		$item = $this->representation(['title' => 'A']);
		$root = $this->representation(['posts' => [$item]]);
		$records = new RecordStateStore();
		$representations = $this->representations($this->trackedRootWithPosts($root));

		$this->session($representations, $records)->sync($root);

		$adopted = $representations->get($item);
		self::assertInstanceOf(RepresentationState::class, $adopted);
		self::assertTrue($adopted->getSingleRecord()?->isNew());
	}

	public function testManyRelationWithCompleteKeyAdoptsUntrackedItemAsNew(): void
	{
		$item = $this->representation(['id' => 5, 'title' => 'A']);
		$root = $this->representation(['posts' => [$item]]);
		$records = new RecordStateStore();
		$representations = $this->representations($this->trackedRootWithPostsAndPostIds($root));

		$this->session($representations, $records)->sync($root);

		$adopted = $representations->get($item);
		self::assertInstanceOf(RepresentationState::class, $adopted);
		$record = $adopted->getSingleRecord();
		self::assertInstanceOf(RecordState::class, $record);
		self::assertTrue($record->isNew());
		self::assertSame(5, $record->getValue('id'));
	}

	public function testManyRelationWithoutCompleteKeyAdoptsUntrackedItemAsNew(): void
	{
		$item = $this->representation(['title' => 'A']);
		$root = $this->representation(['posts' => [$item]]);
		$records = new RecordStateStore();
		$representations = $this->representations($this->trackedRootWithPostsAndPostIds($root));

		$this->session($representations, $records)->sync($root);

		$adopted = $representations->get($item);
		self::assertInstanceOf(RepresentationState::class, $adopted);
		$record = $adopted->getSingleRecord();
		self::assertInstanceOf(RecordState::class, $record);
		self::assertTrue($record->isNew());
	}

	public function testManyRelationWithNonIterableNonNullValueThrows(): void
	{
		$root = $this->representation(['posts' => 'bad']);
		$representations = $this->representations($this->trackedRootWithPosts($root));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('posts');
		$this->expectExceptionMessage('iterable');

		$this->session($representations)->sync($root);
	}

	public function testManyRelationWithNonObjectItemThrows(): void
	{
		$root = $this->representation(['posts' => ['bad']]);
		$representations = $this->representations($this->trackedRootWithPosts($root));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('posts');
		$this->expectExceptionMessage('only contain objects');

		$this->session($representations)->sync($root);
	}

	public function testOneRelationWithNullValueAdoptsNothing(): void
	{
		$root = $this->representation(['profile' => null]);
		$representations = $this->representations($this->trackedRootWithProfile($root));

		$this->session($representations)->sync($root);

		self::assertCount(1, $representations->getAll());
	}

	public function testOneRelationWithObjectValueAdoptsUntrackedTarget(): void
	{
		$target = $this->representation(['label' => 'Profile']);
		$root = $this->representation(['profile' => $target]);
		$representations = $this->representations($this->trackedRootWithProfile($root));

		$this->session($representations)->sync($root);

		self::assertInstanceOf(RepresentationState::class, $representations->get($target));
	}

	public function testOneRelationWithNonObjectNonNullValueThrows(): void
	{
		$root = $this->representation(['profile' => 123]);
		$representations = $this->representations($this->trackedRootWithProfile($root));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('profile');
		$this->expectExceptionMessage('object value or null');

		$this->session($representations)->sync($root);
	}

	public function testAlreadyTrackedRelatedObjectIsNotDuplicated(): void
	{
		$item = $this->representation(['title' => 'A']);
		$root = $this->representation(['posts' => [$item]]);
		$representations = $this->representations(
			$this->trackedRootWithPosts($root),
			$this->tracked($item, $this->postSchemaFor(RecordState::new($this->posts())))
		);

		$this->session($representations)->sync($root);

		self::assertCount(2, $representations->getAll());
	}

	public function testAlreadyTrackedRelatedObjectCanStillBeWalkedOnce(): void
	{
		$comment = $this->representation(['body' => 'Nested']);
		$post = $this->representation(['comments' => [$comment]]);
		$root = $this->representation(['posts' => [$post]]);
		$postSchema = $this->postSchemaFor(RecordState::new($this->posts()));
		$postSchema->addRelation(new RepresentationRelationSchema(
			'comments',
			$this->posts(),
			'comments',
			$this->commentSchema()
		));
		$representations = $this->representations($this->trackedRootWithPosts($root), $this->tracked($post, $postSchema));

		$this->session($representations)->sync($root);

		self::assertInstanceOf(RepresentationState::class, $representations->get($comment));
	}

	public function testRecursiveManyRelationAdoptionWorks(): void
	{
		$comment = $this->representation(['body' => 'Nested']);
		$post = $this->representation(['title' => 'A', 'comments' => [$comment]]);
		$root = $this->representation(['posts' => [$post]]);
		$rootSchema = $this->userSchemaFor(RecordState::new($this->users()));
		$postSchema = $this->postSchema();
		$postSchema->addRelation(new RepresentationRelationSchema(
			'comments',
			$this->posts(),
			'comments',
			$this->commentSchema()
		));
		$rootSchema->addRelation(new RepresentationRelationSchema(
			'posts',
			$this->users(),
			'posts',
			$postSchema
		));
		$representations = $this->representations($this->tracked($root, $rootSchema));

		$this->session($representations)->sync($root);

		self::assertInstanceOf(RepresentationState::class, $representations->get($post));
		self::assertInstanceOf(RepresentationState::class, $representations->get($comment));
	}

	public function testRecursiveOneRelationAdoptionWorks(): void
	{
		$profile = $this->representation(['label' => 'Profile', 'user' => $user = $this->representation(['name' => 'Nested'])]);
		$root = $this->representation(['profile' => $profile]);
		$profileSchema = $this->profileSchema();
		$profileSchema->addRelation(new RepresentationRelationSchema(
			'user',
			$this->profiles(),
			'user',
			$this->userSchema()
		));
		$rootSchema = $this->userSchemaFor(RecordState::new($this->users()));
		$rootSchema->addRelation(new RepresentationRelationSchema(
			'profile',
			$this->users(),
			'profile',
			$profileSchema
		));
		$representations = $this->representations($this->tracked($root, $rootSchema));

		$this->session($representations)->sync($root);

		self::assertInstanceOf(RepresentationState::class, $representations->get($profile));
		self::assertInstanceOf(RepresentationState::class, $representations->get($user));
	}

	public function testCyclicGraphDoesNotInfiniteLoop(): void
	{
		$root = $this->representation([]);
		$item = $this->representation(['title' => 'A']);
		$root->posts = [$item];
		$item->author = $root;
		$postSchema = $this->postSchema();
		$postSchema->addRelation(new RepresentationRelationSchema(
			'author',
			$this->posts(),
			'author',
			$this->userSchema()
		));
		$rootSchema = $this->userSchemaFor(RecordState::new($this->users()));
		$rootSchema->addRelation(new RepresentationRelationSchema(
			'posts',
			$this->users(),
			'posts',
			$postSchema
		));
		$representations = $this->representations($this->tracked($root, $rootSchema));

		$this->session($representations)->sync($root);

		self::assertInstanceOf(RepresentationState::class, $representations->get($item));
	}

	public function testRelatedObjectsAreAdoptedUsingGetRelatedSchema(): void
	{
		$item = $this->representation(['title' => 'A']);
		$root = $this->representation(['posts' => [$item]]);
		$representations = $this->representations($this->trackedRootWithPosts($root));

		$this->session($representations)->sync($root);

		$adopted = $representations->get($item);
		self::assertInstanceOf(RepresentationState::class, $adopted);
		self::assertSame('posts', $adopted->getSchema()->getField('title')->getCollectionName());
	}

	public function testGraphAdoptionDoesNotPlanFlushExecuteOrClearRelationChanges(): void
	{
		$engine = file_get_contents(__DIR__ . '/../../../src/ORM/Representation/Sync/RepresentationAdoptionEngine.php');
		self::assertIsString($engine);
		self::assertStringNotContainsString('RelationPersistencePlanner', $engine);
		self::assertStringNotContainsString('FlushExecutor', $engine);
		self::assertStringNotContainsString('FlushScheduler', $engine);
		self::assertStringNotContainsString('CommandExecutor', $engine);
		self::assertStringNotContainsString('clearChanges', $engine);
	}

	private function session(RepresentationStateStore $representations, ?RecordStateStore $records = null): Session
	{
		return new Session(
			new RecordingCommandExecutor(),
			context: $this->context($representations, $records ?? new RecordStateStore()),
		);
	}

	private function userSchemaWithNameAlias(): RepresentationSchema
	{
		$users = $this->users();
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('id', $users, 'id'));
		$schema->addField(new RepresentationFieldSchema('name', $users, 'name'));
		$schema->addField(new RepresentationFieldSchema('displayName', $users, 'name'));

		return $schema;
	}

	private function userSchemaWithReadOnlyNameAlias(): RepresentationSchema
	{
		$users = $this->users();
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('id', $users, 'id'));
		$schema->addField(new RepresentationFieldSchema('name', $users, 'name'));
		$schema->addField(new RepresentationFieldSchema('upperName', $users, 'name', writable: false));

		return $schema;
	}

	private function trackedRootWithPosts(object $root): RepresentationState
	{
		$schema = $this->userSchemaFor(RecordState::new($this->users()));
		$schema->addRelation(new RepresentationRelationSchema(
			'posts',
			$this->users(),
			'posts',
			$this->postSchema()
		));

		return $this->tracked($root, $schema);
	}

	private function trackedRootWithPostsAndPostIds(object $root): RepresentationState
	{
		$schema = $this->userSchemaFor(RecordState::new($this->users()));
		$schema->addRelation(new RepresentationRelationSchema(
			'posts',
			$this->users(),
			'posts',
			$this->postSchemaWithId()
		));

		return $this->tracked($root, $schema);
	}

	private function trackedRootWithProfile(object $root): RepresentationState
	{
		$schema = $this->userSchemaFor(RecordState::new($this->users()));
		$schema->addRelation(new RepresentationRelationSchema(
			'profile',
			$this->users(),
			'profile',
			$this->profileSchema()
		));

		return $this->tracked($root, $schema);
	}
}
