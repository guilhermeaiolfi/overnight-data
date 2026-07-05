<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Relation\RelatedCollectionStore;
use ON\Data\ORM\Relation\RelatedReferenceStore;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordRelationRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStore;
use ON\Data\ORM\Sync\GraphAdopter;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\ORM\Support\RepresentationStateObjectRegistry;

final class GraphAdopterTest extends TestCase
{
	public function testAdoptRootWithNoRelationBindingsReturnsEmptyResult(): void
	{
		$root = $this->representation(['name' => 'Root']);
		$representations = $this->trackedMap($this->tracked($root, $this->appliedUserBinding(RecordState::new($this->users()))));

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore(), new RelatedCollectionStore(), new RelatedReferenceStore());

		self::assertSame([], $result);
	}

	public function testUntrackedRootWithBindingIsAdopted(): void
	{
		$root = $this->representation(['name' => 'Root']);
		$records = new RecordStateStore();
		$representations = new RepresentationStore();

		$result = $this->adopter()->adopt($root, $representations, $records, new RelatedCollectionStore(), new RelatedReferenceStore(), $this->userBinding());

		self::assertSame([], $result);
		$tracked = $representations->get($root);
		self::assertInstanceOf(RepresentationState::class, $tracked);
		self::assertSame('Root', $records->getFromRepresentation($tracked)?->getValue('name'));
		self::assertTrue($records->getFromRepresentation($tracked)?->isNew());
	}

	public function testUntrackedRootWithoutBindingThrows(): void
	{
		$this->expectException(StateException::class);
		$this->expectExceptionMessage('root binding');

		$this->adopter()->adopt(new stdClass(), new RepresentationStore(), new RecordStateStore(), new RelatedCollectionStore(), new RelatedReferenceStore());
	}

	public function testManyRelationWithNullValueAdoptsNothing(): void
	{
		$root = $this->representation(['posts' => null]);
		$representations = $this->trackedMap($this->trackedRootWithPosts($root));

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore(), new RelatedCollectionStore(), new RelatedReferenceStore());

		self::assertSame([], $result);
	}

	public function testManyRelationWithIterableObjectsAdoptsUntrackedItems(): void
	{
		$item = $this->representation(['title' => 'A']);
		$root = $this->representation(['posts' => [$item]]);
		$records = new RecordStateStore();
		$representations = $this->trackedMap($this->trackedRootWithPosts($root));

		$result = $this->adopter()->adopt($root, $representations, $records, new RelatedCollectionStore(), new RelatedReferenceStore());

		self::assertCount(1, $result);
		self::assertSame($item, RepresentationStateObjectRegistry::objectFor($result[0]));
		self::assertSame($result[0], $representations->get($item));
		self::assertTrue($records->getFromRepresentation($result[0])?->isNew());
	}

	public function testManyRelationWithNonIterableNonNullValueThrows(): void
	{
		$root = $this->representation(['posts' => 'bad']);
		$representations = $this->trackedMap($this->trackedRootWithPosts($root));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('posts');
		$this->expectExceptionMessage('iterable');

		$this->adopter()->adopt($root, $representations, new RecordStateStore(), new RelatedCollectionStore(), new RelatedReferenceStore());
	}

	public function testManyRelationWithNonObjectItemThrows(): void
	{
		$root = $this->representation(['posts' => ['bad']]);
		$representations = $this->trackedMap($this->trackedRootWithPosts($root));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('posts');
		$this->expectExceptionMessage('only contain objects');

		$this->adopter()->adopt($root, $representations, new RecordStateStore(), new RelatedCollectionStore(), new RelatedReferenceStore());
	}

	public function testOneRelationWithNullValueAdoptsNothing(): void
	{
		$root = $this->representation(['profile' => null]);
		$representations = $this->trackedMap($this->trackedRootWithProfile($root));

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore(), new RelatedCollectionStore(), new RelatedReferenceStore());

		self::assertSame([], $result);
	}

	public function testOneRelationWithObjectValueAdoptsUntrackedTarget(): void
	{
		$target = $this->representation(['label' => 'Profile']);
		$root = $this->representation(['profile' => $target]);
		$representations = $this->trackedMap($this->trackedRootWithProfile($root));

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore(), new RelatedCollectionStore(), new RelatedReferenceStore());

		self::assertCount(1, $result);
		self::assertSame($target, RepresentationStateObjectRegistry::objectFor($result[0]));
	}

	public function testOneRelationWithNonObjectNonNullValueThrows(): void
	{
		$root = $this->representation(['profile' => 123]);
		$representations = $this->trackedMap($this->trackedRootWithProfile($root));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('profile');
		$this->expectExceptionMessage('object value or null');

		$this->adopter()->adopt($root, $representations, new RecordStateStore(), new RelatedCollectionStore(), new RelatedReferenceStore());
	}

	public function testAlreadyTrackedRelatedObjectIsNotDuplicated(): void
	{
		$item = $this->representation(['title' => 'A']);
		$root = $this->representation(['posts' => [$item]]);
		$representations = $this->trackedMap(
			$this->trackedRootWithPosts($root),
			$this->tracked($item, $this->appliedPostBinding(RecordState::new($this->posts())))
		);

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore(), new RelatedCollectionStore(), new RelatedReferenceStore());

		self::assertSame([], $result);
		self::assertCount(2, $representations->getAll());
	}

	public function testAlreadyTrackedRelatedObjectCanStillBeWalkedOnce(): void
	{
		$comment = $this->representation(['body' => 'Nested']);
		$post = $this->representation(['comments' => [$comment]]);
		$root = $this->representation(['posts' => [$post]]);
		$postBinding = $this->appliedPostBinding(RecordState::new($this->posts()));
		$postBinding->addRelation(new RepresentationRelationBinding(
			'comments',
			RecordRelationRef::forState(RecordState::new($this->posts()), 'comments'),
			RepresentationRelationCardinality::MANY,
			$this->commentBinding()
		));
		$representations = $this->trackedMap($this->trackedRootWithPosts($root), $this->tracked($post, $postBinding));

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore(), new RelatedCollectionStore(), new RelatedReferenceStore());

		self::assertCount(1, $result);
		self::assertSame($comment, RepresentationStateObjectRegistry::objectFor($result[0]));
	}

	public function testRecursiveManyRelationAdoptionWorks(): void
	{
		$comment = $this->representation(['body' => 'Nested']);
		$post = $this->representation(['title' => 'A', 'comments' => [$comment]]);
		$root = $this->representation(['posts' => [$post]]);
		$rootBinding = $this->appliedUserBinding(RecordState::new($this->users()));
		$postBinding = $this->postBinding();
		$postBinding->addRelation(new RepresentationRelationBinding(
			'comments',
			RecordRelationRef::forCollection($this->posts(), 'comments'),
			RepresentationRelationCardinality::MANY,
			$this->commentBinding()
		));
		$rootBinding->addRelation(new RepresentationRelationBinding(
			'posts',
			RecordRelationRef::forState(RecordState::new($this->users()), 'posts'),
			RepresentationRelationCardinality::MANY,
			$postBinding
		));
		$representations = $this->trackedMap($this->tracked($root, $rootBinding));

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore(), new RelatedCollectionStore(), new RelatedReferenceStore());

		self::assertSame([$post, $comment], array_map(static fn (RepresentationState $tracked): object => RepresentationStateObjectRegistry::objectFor($tracked), $result));
	}

	public function testRecursiveOneRelationAdoptionWorks(): void
	{
		$profile = $this->representation(['user' => $user = $this->representation(['name' => 'Nested'])]);
		$root = $this->representation(['profile' => $profile]);
		$profileBinding = $this->profileBinding();
		$profileBinding->addRelation(new RepresentationRelationBinding(
			'user',
			RecordRelationRef::forCollection($this->profiles(), 'user'),
			RepresentationRelationCardinality::ONE,
			$this->userBinding()
		));
		$rootBinding = $this->appliedUserBinding(RecordState::new($this->users()));
		$rootBinding->addRelation(new RepresentationRelationBinding(
			'profile',
			RecordRelationRef::forState(RecordState::new($this->users()), 'profile'),
			RepresentationRelationCardinality::ONE,
			$profileBinding
		));
		$representations = $this->trackedMap($this->tracked($root, $rootBinding));

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore(), new RelatedCollectionStore(), new RelatedReferenceStore());

		self::assertSame([$profile, $user], array_map(static fn (RepresentationState $tracked): object => RepresentationStateObjectRegistry::objectFor($tracked), $result));
	}

	public function testCyclicGraphDoesNotInfiniteLoop(): void
	{
		$root = $this->representation([]);
		$item = $this->representation([]);
		$root->posts = [$item];
		$item->author = $root;
		$postBinding = $this->postBinding();
		$postBinding->addRelation(new RepresentationRelationBinding(
			'author',
			RecordRelationRef::forCollection($this->posts(), 'author'),
			RepresentationRelationCardinality::ONE,
			$this->userBinding()
		));
		$rootBinding = $this->appliedUserBinding(RecordState::new($this->users()));
		$rootBinding->addRelation(new RepresentationRelationBinding(
			'posts',
			RecordRelationRef::forState(RecordState::new($this->users()), 'posts'),
			RepresentationRelationCardinality::MANY,
			$postBinding
		));
		$representations = $this->trackedMap($this->tracked($root, $rootBinding));

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore(), new RelatedCollectionStore(), new RelatedReferenceStore());

		self::assertCount(1, $result);
		self::assertSame($item, RepresentationStateObjectRegistry::objectFor($result[0]));
	}

	public function testRelatedObjectsAreAdoptedUsingGetRelatedBinding(): void
	{
		$item = $this->representation(['title' => 'A']);
		$root = $this->representation(['posts' => [$item]]);
		$representations = $this->trackedMap($this->trackedRootWithPosts($root));

		$result = $this->adopter()->adopt($root, $representations, new RecordStateStore(), new RelatedCollectionStore(), new RelatedReferenceStore());

		self::assertSame('posts', $result[0]->getBinding()->getField('title')->getField()->getCollectionName());
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
		$binding = $this->appliedUserBinding(RecordState::new($this->users()));
		$binding->addRelation(new RepresentationRelationBinding(
			'posts',
			RecordRelationRef::forState(RecordState::new($this->users()), 'posts'),
			RepresentationRelationCardinality::MANY,
			$this->postBinding()
		));

		return $this->tracked($root, $binding);
	}

	private function trackedRootWithProfile(object $root): RepresentationState
	{
		$binding = $this->appliedUserBinding(RecordState::new($this->users()));
		$binding->addRelation(new RepresentationRelationBinding(
			'profile',
			RecordRelationRef::forState(RecordState::new($this->users()), 'profile'),
			RepresentationRelationCardinality::ONE,
			$this->profileBinding()
		));

		return $this->tracked($root, $binding);
	}

	private function tracked(object $representation, RepresentationBinding $binding): RepresentationState
	{
		return RepresentationStateObjectRegistry::remember(
			$representation,
			new RepresentationState($binding, [])
		);
	}

	private function trackedMap(RepresentationState ...$RepresentationStates): RepresentationStore
	{
		$map = new RepresentationStore();
		foreach ($RepresentationStates as $tracked) {
			RepresentationStateObjectRegistry::addTo($map, $tracked);
		}

		return $map;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function representation(array $values): stdClass
	{
		$representation = new stdClass();
		foreach ($values as $path => $value) {
			$representation->{$path} = $value;
		}

		return $representation;
	}

	private function appliedUserBinding(RecordState $record): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('name', RecordFieldRef::forState($record, 'name')));

		return $binding;
	}

	private function appliedPostBinding(RecordState $record): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('title', RecordFieldRef::forState($record, 'title')));

		return $binding;
	}

	private function userBinding(): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('name', RecordFieldRef::template($this->users(), 'name')));

		return $binding;
	}

	private function postBinding(): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('title', RecordFieldRef::template($this->posts(), 'title')));

		return $binding;
	}

	private function profileBinding(): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('label', RecordFieldRef::template($this->profiles(), 'label')));

		return $binding;
	}

	private function commentBinding(): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('body', RecordFieldRef::template($this->comments(), 'body')));

		return $binding;
	}

	private function users(): CollectionInterface
	{
		return (new Registry())->collection('users')->primaryKey('id')->field('id', 'int')->end()->field('name', 'string')->end();
	}

	private function posts(): CollectionInterface
	{
		return (new Registry())->collection('posts')->primaryKey('id')->field('id', 'int')->end()->field('title', 'string')->end();
	}

	private function profiles(): CollectionInterface
	{
		return (new Registry())->collection('profiles')->primaryKey('id')->field('id', 'int')->end()->field('label', 'string')->end();
	}

	private function comments(): CollectionInterface
	{
		return (new Registry())->collection('comments')->primaryKey('id')->field('id', 'int')->end()->field('body', 'string')->end();
	}
}
