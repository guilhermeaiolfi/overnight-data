<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStateStore;
use ON\Data\ORM\Sync\GraphAdopter;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class GraphAdopterTest extends TestCase
{
	use OrmFixture;

	public function testAdoptRootWithNoRelationBindingsReturnsEmptyResult(): void
	{
		$root = $this->representation(['name' => 'Root']);
		$representations = $this->representations($this->tracked($root, $this->userBindingFor(RecordState::new($this->users()))));

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore());

		self::assertSame([], $result);
	}

	public function testUntrackedRootWithBindingIsAdopted(): void
	{
		$root = $this->representation(['name' => 'Root']);
		$records = new RecordStateStore();
		$representations = new RepresentationStateStore();

		$result = $this->adopter()->adopt($root, $representations, $records, $this->userBinding());

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

		$this->adopter()->adopt($root, $representations, $records, $this->userBindingWithId());

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

		$this->adopter()->adopt($root, $representations, $records, $this->userBindingWithId());

		$tracked = $representations->get($root);
		self::assertInstanceOf(RepresentationState::class, $tracked);
		$record = $records->getFromRepresentation($tracked);
		self::assertInstanceOf(RecordState::class, $record);
		self::assertTrue($record->isNew());
	}

	public function testUntrackedRootWithoutBindingThrows(): void
	{
		$this->expectException(StateException::class);
		$this->expectExceptionMessage('root binding');

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
			$this->tracked($item, $this->postBindingFor(RecordState::new($this->posts())))
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
		$postBinding = $this->postBindingFor(RecordState::new($this->posts()));
		$postBinding->addRelation(new RepresentationRelationBinding(
			'comments',
			$this->posts(),
			'comments',
			$this->commentBinding()
		));
		$representations = $this->representations($this->trackedRootWithPosts($root), $this->tracked($post, $postBinding));

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore());

		self::assertCount(1, $result);
		self::assertSame($representations->get($comment), $result[0]);
	}

	public function testRecursiveManyRelationAdoptionWorks(): void
	{
		$comment = $this->representation(['body' => 'Nested']);
		$post = $this->representation(['title' => 'A', 'comments' => [$comment]]);
		$root = $this->representation(['posts' => [$post]]);
		$rootBinding = $this->userBindingFor(RecordState::new($this->users()));
		$postBinding = $this->postBinding();
		$postBinding->addRelation(new RepresentationRelationBinding(
			'comments',
			$this->posts(),
			'comments',
			$this->commentBinding()
		));
		$rootBinding->addRelation(new RepresentationRelationBinding(
			'posts',
			$this->users(),
			'posts',
			$postBinding
		));
		$representations = $this->representations($this->tracked($root, $rootBinding));

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore());

		self::assertSame([$representations->get($post), $representations->get($comment)], $result);
	}

	public function testRecursiveOneRelationAdoptionWorks(): void
	{
		$profile = $this->representation(['label' => 'Profile', 'user' => $user = $this->representation(['name' => 'Nested'])]);
		$root = $this->representation(['profile' => $profile]);
		$profileBinding = $this->profileBinding();
		$profileBinding->addRelation(new RepresentationRelationBinding(
			'user',
			$this->profiles(),
			'user',
			$this->userBinding()
		));
		$rootBinding = $this->userBindingFor(RecordState::new($this->users()));
		$rootBinding->addRelation(new RepresentationRelationBinding(
			'profile',
			$this->users(),
			'profile',
			$profileBinding
		));
		$representations = $this->representations($this->tracked($root, $rootBinding));

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore());

		self::assertSame([$representations->get($profile), $representations->get($user)], $result);
	}

	public function testCyclicGraphDoesNotInfiniteLoop(): void
	{
		$root = $this->representation([]);
		$item = $this->representation(['title' => 'A']);
		$root->posts = [$item];
		$item->author = $root;
		$postBinding = $this->postBinding();
		$postBinding->addRelation(new RepresentationRelationBinding(
			'author',
			$this->posts(),
			'author',
			$this->userBinding()
		));
		$rootBinding = $this->userBindingFor(RecordState::new($this->users()));
		$rootBinding->addRelation(new RepresentationRelationBinding(
			'posts',
			$this->users(),
			'posts',
			$postBinding
		));
		$representations = $this->representations($this->tracked($root, $rootBinding));

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore());

		self::assertCount(1, $result);
		self::assertSame($representations->get($item), $result[0]);
	}

	public function testRelatedObjectsAreAdoptedUsingGetRelatedBinding(): void
	{
		$item = $this->representation(['title' => 'A']);
		$root = $this->representation(['posts' => [$item]]);
		$representations = $this->representations($this->trackedRootWithPosts($root));

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore());

		self::assertSame('posts', $result[0]->getBinding()->getField('title')->getCollectionName());
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
		$binding = $this->userBindingFor(RecordState::new($this->users()));
		$binding->addRelation(new RepresentationRelationBinding(
			'posts',
			$this->users(),
			'posts',
			$this->postBinding()
		));

		return $this->tracked($root, $binding);
	}

	private function trackedRootWithPostsAndPostIds(object $root): RepresentationState
	{
		$binding = $this->userBindingFor(RecordState::new($this->users()));
		$binding->addRelation(new RepresentationRelationBinding(
			'posts',
			$this->users(),
			'posts',
			$this->postBindingWithId()
		));

		return $this->tracked($root, $binding);
	}

	private function trackedRootWithProfile(object $root): RepresentationState
	{
		$binding = $this->userBindingFor(RecordState::new($this->users()));
		$binding->addRelation(new RepresentationRelationBinding(
			'profile',
			$this->users(),
			'profile',
			$this->profileBinding()
		));

		return $this->tracked($root, $binding);
	}
}
