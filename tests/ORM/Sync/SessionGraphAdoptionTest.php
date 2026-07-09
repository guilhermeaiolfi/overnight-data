<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Session;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Representation\Schema\RepresentationRelationSchema;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\State\RepresentationStateStore;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\ORM\Support\OrmFixture;
use Tests\ON\Data\Support\RecordingCommandExecutor;

final class SessionGraphAdoptionTest extends TestCase
{
	use OrmFixture;

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
		self::assertSame('Root', $records->getFromRepresentation($tracked)?->getValue('name'));
		self::assertTrue($records->getFromRepresentation($tracked)?->isNew());
	}

	public function testUntrackedRootWithCompleteKeyIsAdoptedAsCleanExisting(): void
	{
		$root = $this->representation(['id' => 10, 'name' => 'Root']);
		$records = new RecordStateStore();
		$representations = new RepresentationStateStore();

		$this->session($representations, $records)->sync($root, $this->userSchemaWithId());

		$tracked = $representations->get($root);
		self::assertInstanceOf(RepresentationState::class, $tracked);
		$record = $records->getFromRepresentation($tracked);
		self::assertInstanceOf(RecordState::class, $record);
		self::assertTrue($record->isClean());
		self::assertSame(10, $record->getKey()?->getFieldValue('id'));
		self::assertSame('Root', $record->getValue('name'));
	}

	public function testUntrackedRootWithoutCompleteKeyIsAdoptedAsNew(): void
	{
		$root = $this->representation(['id' => null, 'name' => 'Root']);
		$records = new RecordStateStore();
		$representations = new RepresentationStateStore();

		$this->session($representations, $records)->sync($root, $this->userSchemaWithId());

		$tracked = $representations->get($root);
		self::assertInstanceOf(RepresentationState::class, $tracked);
		$record = $records->getFromRepresentation($tracked);
		self::assertInstanceOf(RecordState::class, $record);
		self::assertTrue($record->isNew());
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
		self::assertTrue($records->getFromRepresentation($adopted)?->isNew());
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
		$record = $records->getFromRepresentation($adopted);
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
		$record = $records->getFromRepresentation($adopted);
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
		$source = file_get_contents(__DIR__ . '/../../../src/ORM/Representation/Sync/RepresentationStateAdoptionTrait.php');

		self::assertIsString($source);
		self::assertStringNotContainsString('RelationPersistencePlanner', $source);
		self::assertStringNotContainsString('FlushExecutor', $source);
		self::assertStringNotContainsString('RecordFlusher', $source);
		self::assertStringNotContainsString('CommandExecutor', $source);
		self::assertStringNotContainsString('clearChanges', $source);
	}

	private function session(RepresentationStateStore $representations, ?RecordStateStore $records = null): Session
	{
		return new Session(
			new RecordingCommandExecutor(),
			context: $this->context($representations, $records ?? new RecordStateStore()),
		);
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
