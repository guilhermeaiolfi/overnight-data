<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Relation\RelatedCollectionMap;
use ON\Data\ORM\Relation\RelatedReferenceMap;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordRelationRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateMap;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use ON\Data\ORM\State\TrackedRepresentation;
use ON\Data\ORM\State\TrackedRepresentationMap;
use ON\Data\ORM\Sync\GraphAdopter;
use PHPUnit\Framework\TestCase;
use stdClass;

final class GraphAdopterTest extends TestCase
{
	public function testAdoptRootWithNoRelationBindingsReturnsEmptyResult(): void
	{
		$root = $this->representation(['name' => 'Root']);
		$representations = $this->trackedMap($this->tracked($root, $this->appliedUserBinding(RecordState::new($this->users()))));

		$result = $this->adopter()->adopt($root, $representations, new RecordStateMap(), new RelatedCollectionMap(), new RelatedReferenceMap());

		self::assertTrue($result->isEmpty());
		self::assertSame(0, $result->getCount());
		self::assertSame([], $result->getTrackedRepresentations());
	}

	public function testUntrackedRootThrows(): void
	{
		$this->expectException(StateException::class);
		$this->expectExceptionMessage('root representation is not tracked');

		$this->adopter()->adopt(new stdClass(), new TrackedRepresentationMap(), new RecordStateMap(), new RelatedCollectionMap(), new RelatedReferenceMap());
	}

	public function testManyRelationWithNullValueAdoptsNothing(): void
	{
		$root = $this->representation(['posts' => null]);
		$representations = $this->trackedMap($this->trackedRootWithPosts($root));

		$result = $this->adopter()->adopt($root, $representations, new RecordStateMap(), new RelatedCollectionMap(), new RelatedReferenceMap());

		self::assertTrue($result->isEmpty());
	}

	public function testManyRelationWithIterableObjectsAdoptsUntrackedItems(): void
	{
		$item = $this->representation(['title' => 'A']);
		$root = $this->representation(['posts' => [$item]]);
		$records = new RecordStateMap();
		$representations = $this->trackedMap($this->trackedRootWithPosts($root));

		$result = $this->adopter()->adopt($root, $representations, $records, new RelatedCollectionMap(), new RelatedReferenceMap());

		self::assertSame(1, $result->getCount());
		self::assertSame($item, $result->getTrackedRepresentations()[0]->getRepresentation());
		self::assertSame($result->getTrackedRepresentations()[0], $representations->get($item));
		self::assertTrue($records->getFromRepresentation($result->getTrackedRepresentations()[0])?->isNew());
	}

	public function testManyRelationWithNonIterableNonNullValueThrows(): void
	{
		$root = $this->representation(['posts' => 'bad']);
		$representations = $this->trackedMap($this->trackedRootWithPosts($root));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('posts');
		$this->expectExceptionMessage('iterable');

		$this->adopter()->adopt($root, $representations, new RecordStateMap(), new RelatedCollectionMap(), new RelatedReferenceMap());
	}

	public function testManyRelationWithNonObjectItemThrows(): void
	{
		$root = $this->representation(['posts' => ['bad']]);
		$representations = $this->trackedMap($this->trackedRootWithPosts($root));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('posts');
		$this->expectExceptionMessage('only contain objects');

		$this->adopter()->adopt($root, $representations, new RecordStateMap(), new RelatedCollectionMap(), new RelatedReferenceMap());
	}

	public function testOneRelationWithNullValueAdoptsNothing(): void
	{
		$root = $this->representation(['profile' => null]);
		$representations = $this->trackedMap($this->trackedRootWithProfile($root));

		$result = $this->adopter()->adopt($root, $representations, new RecordStateMap(), new RelatedCollectionMap(), new RelatedReferenceMap());

		self::assertTrue($result->isEmpty());
	}

	public function testOneRelationWithObjectValueAdoptsUntrackedTarget(): void
	{
		$target = $this->representation(['label' => 'Profile']);
		$root = $this->representation(['profile' => $target]);
		$representations = $this->trackedMap($this->trackedRootWithProfile($root));

		$result = $this->adopter()->adopt($root, $representations, new RecordStateMap(), new RelatedCollectionMap(), new RelatedReferenceMap());

		self::assertSame(1, $result->getCount());
		self::assertSame($target, $result->getTrackedRepresentations()[0]->getRepresentation());
	}

	public function testOneRelationWithNonObjectNonNullValueThrows(): void
	{
		$root = $this->representation(['profile' => 123]);
		$representations = $this->trackedMap($this->trackedRootWithProfile($root));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('profile');
		$this->expectExceptionMessage('object value or null');

		$this->adopter()->adopt($root, $representations, new RecordStateMap(), new RelatedCollectionMap(), new RelatedReferenceMap());
	}

	public function testAlreadyTrackedRelatedObjectIsNotDuplicated(): void
	{
		$item = $this->representation(['title' => 'A']);
		$root = $this->representation(['posts' => [$item]]);
		$representations = $this->trackedMap(
			$this->trackedRootWithPosts($root),
			$this->tracked($item, $this->appliedPostBinding(RecordState::new($this->posts())))
		);

		$result = $this->adopter()->adopt($root, $representations, new RecordStateMap(), new RelatedCollectionMap(), new RelatedReferenceMap());

		self::assertTrue($result->isEmpty());
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

		$result = $this->adopter()->adopt($root, $representations, new RecordStateMap(), new RelatedCollectionMap(), new RelatedReferenceMap());

		self::assertSame(1, $result->getCount());
		self::assertSame($comment, $result->getTrackedRepresentations()[0]->getRepresentation());
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

		$result = $this->adopter()->adopt($root, $representations, new RecordStateMap(), new RelatedCollectionMap(), new RelatedReferenceMap());

		self::assertSame([$post, $comment], array_map(static fn (TrackedRepresentation $tracked): object => $tracked->getRepresentation(), $result->getTrackedRepresentations()));
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

		$result = $this->adopter()->adopt($root, $representations, new RecordStateMap(), new RelatedCollectionMap(), new RelatedReferenceMap());

		self::assertSame([$profile, $user], array_map(static fn (TrackedRepresentation $tracked): object => $tracked->getRepresentation(), $result->getTrackedRepresentations()));
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

		$result = $this->adopter()->adopt($root, $representations, new RecordStateMap(), new RelatedCollectionMap(), new RelatedReferenceMap());

		self::assertSame(1, $result->getCount());
		self::assertSame($item, $result->getTrackedRepresentations()[0]->getRepresentation());
	}

	public function testRelatedObjectsAreAdoptedUsingGetRelatedBinding(): void
	{
		$item = $this->representation(['title' => 'A']);
		$root = $this->representation(['posts' => [$item]]);
		$representations = $this->trackedMap($this->trackedRootWithPosts($root));

		$result = $this->adopter()->adopt($root, $representations, new RecordStateMap(), new RelatedCollectionMap(), new RelatedReferenceMap());

		self::assertSame('posts', $result->getTrackedRepresentations()[0]->getBinding()->getField('title')->getField()->getCollectionName());
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

	private function trackedRootWithPosts(object $root): TrackedRepresentation
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

	private function trackedRootWithProfile(object $root): TrackedRepresentation
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

	private function tracked(object $representation, RepresentationBinding $binding): TrackedRepresentation
	{
		return new TrackedRepresentation($representation, $binding, []);
	}

	private function trackedMap(TrackedRepresentation ...$trackedRepresentations): TrackedRepresentationMap
	{
		$map = new TrackedRepresentationMap();
		foreach ($trackedRepresentations as $tracked) {
			$map->add($tracked);
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
