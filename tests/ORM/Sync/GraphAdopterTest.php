<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationRelationSchema;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStateStore;
use ON\Data\ORM\Sync\GraphAdopter;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class GraphAdopterTest extends TestCase
{
	use OrmFixture;

	public function testAdoptRootWithNoRelationSchemasReturnsEmptyResult(): void
	{
		$root = $this->representation(['name' => 'Root']);
		$representations = $this->representations($this->tracked($root, $this->userSchemaFor(RecordState::new($this->users()))));

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore());

		self::assertSame([], $result);
	}

	public function testUntrackedRootWithSchemaIsAdopted(): void
	{
		$root = $this->representation(['name' => 'Root']);
		$records = new RecordStateStore();
		$representations = new RepresentationStateStore();

		$result = $this->adopter()->adopt($root, $representations, $records, $this->userSchema());

		self::assertSame([], $result);
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

		$this->adopter()->adopt($root, $representations, $records, $this->userSchemaWithId());

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

		$this->adopter()->adopt($root, $representations, $records, $this->userSchemaWithId());

		$tracked = $representations->get($root);
		self::assertInstanceOf(RepresentationState::class, $tracked);
		$record = $records->getFromRepresentation($tracked);
		self::assertInstanceOf(RecordState::class, $record);
		self::assertTrue($record->isNew());
	}

	public function testUntrackedRootWithoutSchemaThrows(): void
	{
		$this->expectException(StateException::class);
		$this->expectExceptionMessage('root schema');

		$this->adopter()->adopt(new stdClass(), new RepresentationStateStore(), new RecordStateStore());
	}

	public function testManyRelationWithNullValueAdoptsNothing(): void
	{
		$root = $this->representation(['posts' => null]);
		$representations = $this->representations($this->trackedRootWithPosts($root));

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore());

		self::assertSame([], $result);
	}

	public function testManyRelationWithIterableObjectsAdoptsUntrackedItems(): void
	{
		$item = $this->representation(['title' => 'A']);
		$root = $this->representation(['posts' => [$item]]);
		$records = new RecordStateStore();
		$representations = $this->representations($this->trackedRootWithPosts($root));

		$result = $this->adopter()->adopt($root, $representations, $records);

		self::assertCount(1, $result);
		self::assertSame($representations->get($item), $result[0]);
		self::assertSame($result[0], $representations->get($item));
		self::assertTrue($records->getFromRepresentation($result[0])?->isNew());
	}

	public function testManyRelationWithCompleteKeyAdoptsUntrackedItemAsNew(): void
	{
		$item = $this->representation(['id' => 5, 'title' => 'A']);
		$root = $this->representation(['posts' => [$item]]);
		$records = new RecordStateStore();
		$representations = $this->representations($this->trackedRootWithPostsAndPostIds($root));

		$result = $this->adopter()->adopt($root, $representations, $records);

		self::assertCount(1, $result);
		$record = $records->getFromRepresentation($result[0]);
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

		$result = $this->adopter()->adopt($root, $representations, $records);

		self::assertCount(1, $result);
		$record = $records->getFromRepresentation($result[0]);
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

		$this->adopter()->adopt($root, $representations, new RecordStateStore());
	}

	public function testManyRelationWithNonObjectItemThrows(): void
	{
		$root = $this->representation(['posts' => ['bad']]);
		$representations = $this->representations($this->trackedRootWithPosts($root));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('posts');
		$this->expectExceptionMessage('only contain objects');

		$this->adopter()->adopt($root, $representations, new RecordStateStore());
	}

	public function testOneRelationWithNullValueAdoptsNothing(): void
	{
		$root = $this->representation(['profile' => null]);
		$representations = $this->representations($this->trackedRootWithProfile($root));

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore());

		self::assertSame([], $result);
	}

	public function testOneRelationWithObjectValueAdoptsUntrackedTarget(): void
	{
		$target = $this->representation(['label' => 'Profile']);
		$root = $this->representation(['profile' => $target]);
		$representations = $this->representations($this->trackedRootWithProfile($root));

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore());

		self::assertCount(1, $result);
		self::assertSame($representations->get($target), $result[0]);
	}

	public function testOneRelationWithNonObjectNonNullValueThrows(): void
	{
		$root = $this->representation(['profile' => 123]);
		$representations = $this->representations($this->trackedRootWithProfile($root));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('profile');
		$this->expectExceptionMessage('object value or null');

		$this->adopter()->adopt($root, $representations, new RecordStateStore());
	}

	public function testAlreadyTrackedRelatedObjectIsNotDuplicated(): void
	{
		$item = $this->representation(['title' => 'A']);
		$root = $this->representation(['posts' => [$item]]);
		$representations = $this->representations(
			$this->trackedRootWithPosts($root),
			$this->tracked($item, $this->postSchemaFor(RecordState::new($this->posts())))
		);

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore());

		self::assertSame([], $result);
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

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore());

		self::assertCount(1, $result);
		self::assertSame($representations->get($comment), $result[0]);
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

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore());

		self::assertSame([$representations->get($post), $representations->get($comment)], $result);
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

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore());

		self::assertSame([$representations->get($profile), $representations->get($user)], $result);
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

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore());

		self::assertCount(1, $result);
		self::assertSame($representations->get($item), $result[0]);
	}

	public function testRelatedObjectsAreAdoptedUsingGetRelatedSchema(): void
	{
		$item = $this->representation(['title' => 'A']);
		$root = $this->representation(['posts' => [$item]]);
		$representations = $this->representations($this->trackedRootWithPosts($root));

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore());

		self::assertSame('posts', $result[0]->getSchema()->getField('title')->getCollectionName());
	}

	public function testGraphAdoptionDoesNotPlanFlushExecuteOrClearRelationChanges(): void
	{
		$source = file_get_contents(__DIR__ . '/../../../src/ORM/Sync/GraphAdopter.php');

		self::assertIsString($source);
		self::assertStringNotContainsString('RelationPersistencePlanner', $source);
		self::assertStringNotContainsString('FlushExecutor', $source);
		self::assertStringNotContainsString('RecordFlusher', $source);
		self::assertStringNotContainsString('CommandExecutor', $source);
		self::assertStringNotContainsString('clearChanges', $source);
	}

	private function adopter(): GraphAdopter
	{
		return new GraphAdopter();
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
