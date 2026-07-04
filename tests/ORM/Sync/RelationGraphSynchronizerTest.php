<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Relation\RelatedCollection;
use ON\Data\ORM\Relation\RelatedCollectionMap;
use ON\Data\ORM\Relation\RelationCollectionState;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordRelationRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use ON\Data\ORM\State\TrackedRepresentation;
use ON\Data\ORM\State\TrackedRepresentationMap;
use ON\Data\ORM\Sync\RelationGraphSynchronizer;
use PHPUnit\Framework\TestCase;
use stdClass;

final class RelationGraphSynchronizerTest extends TestCase
{
	public function testReturnsEmptyListWhenNoTrackedRepresentationsExist(): void
	{
		self::assertSame([], $this->synchronizer()->sync(new TrackedRepresentationMap(), new RelatedCollectionMap()));
	}

	public function testIgnoresBindingsWithNoRelationBindings(): void
	{
		$owner = RecordState::new($this->users());
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('name', RecordFieldRef::forState($owner, 'name')));
		$relations = new RelatedCollectionMap();

		$touched = $this->synchronizer()->sync(
			$this->trackedMap($this->tracked($this->representation(['name' => 'Ada']), $binding)),
			$relations
		);

		self::assertSame([], $touched);
		self::assertSame([], $relations->getAll());
	}

	public function testIgnoresOneRelationBindings(): void
	{
		$owner = RecordState::new($this->users());
		$binding = new RepresentationBinding();
		$binding->addRelation($this->relationBinding($owner, RepresentationRelationCardinality::ONE));
		$relations = new RelatedCollectionMap();

		$touched = $this->synchronizer()->sync(
			$this->trackedMap($this->tracked($this->representation(['posts' => [new stdClass()]]), $binding)),
			$relations
		);

		self::assertSame([], $touched);
		self::assertSame([], $relations->getAll());
	}

	public function testManyRelationWithTemplateRecordRelationRefThrowsSyncException(): void
	{
		$binding = new RepresentationBinding();
		$binding->addRelation(new RepresentationRelationBinding(
			'posts',
			RecordRelationRef::forCollection($this->users(), 'posts'),
			RepresentationRelationCardinality::MANY,
			$this->postBinding()
		));

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('concrete record');

		$this->synchronizer()->sync(
			$this->trackedMap($this->tracked($this->representation(['posts' => []]), $binding)),
			new RelatedCollectionMap()
		);
	}

	public function testManyRelationWithNullValueCreatesTouchedCollectionButAddsNoItems(): void
	{
		$owner = RecordState::new($this->users());
		$relations = new RelatedCollectionMap();

		$touched = $this->synchronizer()->sync(
			$this->trackedMap($this->trackedWithRelation($owner, ['posts' => null])),
			$relations
		);

		self::assertCount(1, $touched);
		self::assertSame([], $touched[0]->getAdded());
		self::assertSame([], $touched[0]->getItems());
		self::assertSame($touched[0], $relations->get($owner, 'posts'));
	}

	public function testManyRelationWithNonIterableNonNullValueThrowsSyncException(): void
	{
		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('iterable');

		$this->synchronizer()->sync(
			$this->trackedMap($this->trackedWithRelation(RecordState::new($this->users()), ['posts' => 'not iterable'])),
			new RelatedCollectionMap()
		);
	}

	public function testManyRelationWithNonObjectItemThrowsSyncException(): void
	{
		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('only contain objects');

		$this->synchronizer()->sync(
			$this->trackedMap($this->trackedWithRelation(RecordState::new($this->users()), ['posts' => ['not object']])),
			new RelatedCollectionMap()
		);
	}

	public function testUnloadedManyRelationTreatsCurrentObjectsAsAdded(): void
	{
		$item = new stdClass();
		$touched = $this->syncOne(RecordState::new($this->users()), [$item], RelationCollectionState::UNLOADED);

		self::assertSame([$item], $touched[0]->getAdded());
		self::assertSame([$item], $touched[0]->getItems());
		self::assertTrue($touched[0]->isPartiallyLoaded());
	}

	public function testPartiallyLoadedManyRelationTreatsCurrentObjectsAsAdded(): void
	{
		$item = new stdClass();
		$touched = $this->syncOne(RecordState::new($this->users()), [$item], RelationCollectionState::PARTIALLY_LOADED);

		self::assertSame([$item], $touched[0]->getAdded());
		self::assertSame([$item], $touched[0]->getItems());
		self::assertTrue($touched[0]->isPartiallyLoaded());
	}

	public function testFullyLoadedManyRelationAddsNewCurrentObjectsNotInKnownBaseline(): void
	{
		$known = new stdClass();
		$added = new stdClass();
		$owner = RecordState::new($this->users());
		$collection = new RelatedCollection($owner, 'posts', $this->postBinding(), RelationCollectionState::FULLY_LOADED, [$known]);
		$relations = $this->relations($collection);

		$this->synchronizer()->sync(
			$this->trackedMap($this->trackedWithRelation($owner, ['posts' => [$known, $added]], RelationCollectionState::FULLY_LOADED)),
			$relations
		);

		self::assertSame([$added], $collection->getAdded());
		self::assertSame([], $collection->getRemoved());
		self::assertSame([$known, $added], $collection->getItems());
	}

	public function testFullyLoadedManyRelationRemovesKnownBaselineObjectsMissingFromCurrent(): void
	{
		$kept = new stdClass();
		$removed = new stdClass();
		$owner = RecordState::new($this->users());
		$collection = new RelatedCollection($owner, 'posts', $this->postBinding(), RelationCollectionState::FULLY_LOADED, [$kept, $removed]);

		$this->synchronizer()->sync(
			$this->trackedMap($this->trackedWithRelation($owner, ['posts' => [$kept]], RelationCollectionState::FULLY_LOADED)),
			$this->relations($collection)
		);

		self::assertSame([], $collection->getAdded());
		self::assertSame([$removed], $collection->getRemoved());
		self::assertSame([$kept], $collection->getItems());
	}

	public function testFullyLoadedManyRelationKeepsUnchangedKnownObjectsUnchanged(): void
	{
		$known = new stdClass();
		$owner = RecordState::new($this->users());
		$collection = new RelatedCollection($owner, 'posts', $this->postBinding(), RelationCollectionState::FULLY_LOADED, [$known]);

		$this->synchronizer()->sync(
			$this->trackedMap($this->trackedWithRelation($owner, ['posts' => [$known]], RelationCollectionState::FULLY_LOADED)),
			$this->relations($collection)
		);

		self::assertSame([], $collection->getAdded());
		self::assertSame([], $collection->getRemoved());
		self::assertSame([$known], $collection->getItems());
	}

	public function testExistingRelatedCollectionInMapIsReused(): void
	{
		$owner = RecordState::new($this->users());
		$existing = new RelatedCollection($owner, 'posts', $this->postBinding());
		$relations = $this->relations($existing);

		$touched = $this->synchronizer()->sync(
			$this->trackedMap($this->trackedWithRelation($owner, ['posts' => [new stdClass()]])),
			$relations
		);

		self::assertSame([$existing], $touched);
		self::assertSame($existing, $relations->get($owner, 'posts'));
	}

	public function testCreatedRelatedCollectionUsesOwnerStateFromRecordRelationRef(): void
	{
		$owner = RecordState::new($this->users());
		$touched = $this->syncOne($owner, []);

		self::assertSame($owner, $touched[0]->getOwner());
	}

	public function testCreatedRelatedCollectionUsesRelationNameFromRecordRelationRef(): void
	{
		$touched = $this->syncOne(RecordState::new($this->users()), []);

		self::assertSame('posts', $touched[0]->getRelationName());
	}

	public function testCreatedRelatedCollectionUsesRelatedBindingFromRepresentationRelationBinding(): void
	{
		$owner = RecordState::new($this->users());
		$relatedBinding = $this->postBinding();
		$binding = new RepresentationBinding();
		$binding->addRelation(new RepresentationRelationBinding(
			'posts',
			RecordRelationRef::forState($owner, 'posts'),
			RepresentationRelationCardinality::MANY,
			$relatedBinding
		));

		$touched = $this->synchronizer()->sync(
			$this->trackedMap($this->tracked($this->representation(['posts' => []]), $binding)),
			new RelatedCollectionMap()
		);

		self::assertSame($relatedBinding, $touched[0]->getRelatedBinding());
	}

	public function testCreatedRelatedCollectionUsesCollectionStateFromRepresentationRelationBinding(): void
	{
		$touched = $this->syncOne(RecordState::new($this->users()), [], RelationCollectionState::FULLY_LOADED);

		self::assertTrue($touched[0]->isFullyLoaded());
	}

	public function testTouchedCollectionsAreReturnedOnceEvenIfReachedTwice(): void
	{
		$item = new stdClass();
		$owner = RecordState::new($this->users());

		$touched = $this->synchronizer()->sync(
			$this->trackedMap(
				$this->trackedWithRelation($owner, ['posts' => [$item]]),
				$this->trackedWithRelation($owner, ['posts' => [$item]])
			),
			new RelatedCollectionMap()
		);

		self::assertCount(1, $touched);
		self::assertSame([$item], $touched[0]->getAdded());
	}

	public function testSynchronizerDoesNotAutoAdoptChildObjects(): void
	{
		$item = new stdClass();
		$representations = $this->trackedMap($this->trackedWithRelation(RecordState::new($this->users()), ['posts' => [$item]]));

		$touched = $this->synchronizer()->sync($representations, new RelatedCollectionMap());

		self::assertNull($representations->get($item));
		self::assertSame([$item], $touched[0]->getAdded());
	}

	public function testSynchronizerDoesNotExecuteCommandsOrCallRelationPersistencePlanners(): void
	{
		$source = file_get_contents(__DIR__ . '/../../../src/ORM/Sync/RelationGraphSynchronizer.php');

		self::assertIsString($source);
		self::assertStringNotContainsString('CommandExecutor', $source);
		self::assertStringNotContainsString('CommandInterface', $source);
		self::assertStringNotContainsString('RelationPersistencePlanner', $source);
		self::assertStringNotContainsString('RelationPersistenceSynchronizer', $source);
	}

	/**
	 * @param list<object> $items
	 * @return list<RelatedCollection>
	 */
	private function syncOne(
		RecordState $owner,
		array $items,
		RelationCollectionState $state = RelationCollectionState::UNLOADED,
	): array {
		return $this->synchronizer()->sync(
			$this->trackedMap($this->trackedWithRelation($owner, ['posts' => $items], $state)),
			new RelatedCollectionMap()
		);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function trackedWithRelation(
		RecordState $owner,
		array $values,
		RelationCollectionState $state = RelationCollectionState::UNLOADED,
	): TrackedRepresentation {
		$binding = new RepresentationBinding();
		$binding->addRelation($this->relationBinding($owner, RepresentationRelationCardinality::MANY, $state));

		return $this->tracked($this->representation($values), $binding);
	}

	private function relationBinding(
		RecordState $owner,
		RepresentationRelationCardinality $cardinality,
		RelationCollectionState $state = RelationCollectionState::UNLOADED,
	): RepresentationRelationBinding {
		return new RepresentationRelationBinding(
			'posts',
			RecordRelationRef::forState($owner, 'posts'),
			$cardinality,
			$this->postBinding(),
			$state
		);
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

	private function relations(RelatedCollection ...$collections): RelatedCollectionMap
	{
		$map = new RelatedCollectionMap();
		foreach ($collections as $collection) {
			$map->add($collection);
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

	private function synchronizer(): RelationGraphSynchronizer
	{
		return new RelationGraphSynchronizer();
	}

	private function postBinding(): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('title', RecordFieldRef::template($this->posts(), 'title')));

		return $binding;
	}

	private function users(): CollectionInterface
	{
		return (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
	}

	private function posts(): CollectionInterface
	{
		return (new Registry())
			->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end();
	}
}
