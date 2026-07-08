<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Relation\RelationChangeInterface;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStateStore;
use ON\Data\ORM\Sync\RelationRepresentationSynchronizer;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\ORM\Support\OrmFixture;
use Tests\ON\Data\ORM\Support\RepresentationStateObjectRegistry;

final class RelationRepresentationSynchronizerTest extends TestCase
{
	use OrmFixture;

	public function testReturnsEmptyListWhenNoRepresentationStatesExist(): void
	{
		self::assertSame([], $this->sync(new RepresentationStateStore()));
	}

	public function testIgnoresBindingsWithNoRelationBindings(): void
	{
		$owner = RecordState::new($this->users());
		$binding = new RepresentationBinding($this->users());
		$binding->addField(new RepresentationFieldBinding('name', $owner->getCollection(), 'name'));
		$toManyRelations = new RelationStateStore();

		$touched = $this->sync(
			$this->representations($this->tracked($this->representation(['name' => 'Ada']), $binding)),
			$toManyRelations
		);

		self::assertSame([], $touched);
		self::assertSame([], $toManyRelations->getAll());
	}

	public function testOneRelationWithNullValueCreatesTouchedReferenceWithNoTarget(): void
	{
		$owner = RecordState::new($this->users());
		$binding = new RepresentationBinding($this->users());
		$binding->addRelation($this->relationBinding($owner, RepresentationRelationCardinality::ONE));
		$toManyRelations = new RelationStateStore();
		$toOneRelations = new RelationStateStore();

		$touched = $this->sync(
			$this->representations($this->tracked($this->representation(['profile' => null]), $binding, [$owner])),
			$toManyRelations,
			$toOneRelations
		);

		self::assertSame([], $toManyRelations->getAll());
		self::assertCount(1, $touched);
		self::assertInstanceOf(ToOneRelationState::class, $touched[0]);
		self::assertFalse($touched[0]->hasTarget());
		self::assertFalse($touched[0]->hasChanges());
		self::assertSame($touched[0], $toOneRelations->get($owner, 'profile'));
	}

	public function testOneRelationWithObjectValueCreatesTouchedReferenceAndSetsTarget(): void
	{
		$owner = RecordState::new($this->users());
		$target = new stdClass();
		$toOneRelations = new RelationStateStore();

		$touched = $this->sync(
			$this->trackedMapWithRelated($this->trackedWithOneRelation($owner, ['profile' => $target]), $target),
			toOneRelations: $toOneRelations
		);

		self::assertCount(1, $touched);
		self::assertInstanceOf(ToOneRelationState::class, $touched[0]);
		self::assertSame($target, $touched[0]->getTarget());
		self::assertTrue($touched[0]->hasChanges());
		self::assertSame($touched[0], $toOneRelations->get($owner, 'profile'));
	}

	public function testOneRelationWithNonObjectNonNullValueThrowsSyncException(): void
	{
		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('object value or null');

		$this->sync($this->representations($this->trackedWithOneRelation(RecordState::new($this->users()), ['profile' => 'bad'])));
	}

	public function testExistingToOneRelationStateInMapIsReused(): void
	{
		$owner = RecordState::new($this->users());
		$target = new stdClass();
		$existing = new ToOneRelationState($owner, 'profile', $this->profileBinding());
		$toOneRelations = $this->toOneRelations($existing);

		$touched = $this->sync(
			$this->trackedMapWithRelated($this->trackedWithOneRelation($owner, ['profile' => $target]), $target),
			toOneRelations: $toOneRelations
		);

		self::assertSame([$existing], $touched);
		self::assertSame($target, $existing->getTarget());
		self::assertSame($existing, $toOneRelations->get($owner, 'profile'));
	}

	public function testTouchedChangesIncludeCollectionAndReferenceOnceEach(): void
	{
		$owner = RecordState::new($this->users());
		$item = new stdClass();
		$target = new stdClass();
		$binding = new RepresentationBinding($this->users());
		$binding->addRelation($this->relationBinding($owner, RepresentationRelationCardinality::MANY));
		$binding->addRelation($this->relationBinding($owner, RepresentationRelationCardinality::ONE));

		$touched = $this->sync(
			$this->trackedMapWithRelated(
				$this->tracked($this->representation(['posts' => [$item], 'profile' => $target]), $binding, [$owner]),
				$this->tracked($this->representation(['posts' => [$item], 'profile' => $target]), $binding, [$owner]),
				$item,
				$target
			)
		);

		self::assertCount(2, $touched);
		self::assertInstanceOf(ToManyRelationState::class, $touched[0]);
		self::assertInstanceOf(ToOneRelationState::class, $touched[1]);
	}

	public function testOneRelationWithUntrackedTargetThrowsSyncException(): void
	{
		$target = new stdClass();
		$representations = $this->representations($this->trackedWithOneRelation(RecordState::new($this->users()), ['profile' => $target]));

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('profile');
		$this->expectExceptionMessage('not tracked');

		$this->sync($representations);
	}

	public function testManyRelationWithNullValueCreatesTouchedCollectionButAddsNoItems(): void
	{
		$owner = RecordState::new($this->users());
		$toManyRelations = new RelationStateStore();

		$touched = $this->sync(
			$this->representations($this->trackedWithRelation($owner, ['posts' => null])),
			$toManyRelations
		);

		self::assertCount(1, $touched);
		self::assertSame([], $touched[0]->getAdded());
		self::assertSame([], $touched[0]->getItems());
		self::assertSame($touched[0], $toManyRelations->get($owner, 'posts'));
	}

	public function testManyRelationWithNonIterableNonNullValueThrowsSyncException(): void
	{
		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('iterable');

		$this->sync(
			$this->representations($this->trackedWithRelation(RecordState::new($this->users()), ['posts' => 'not iterable'])),
			new RelationStateStore()
		);
	}

	public function testManyRelationWithNonObjectItemThrowsSyncException(): void
	{
		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('only contain objects');

		$this->sync(
			$this->representations($this->trackedWithRelation(RecordState::new($this->users()), ['posts' => ['not object']])),
			new RelationStateStore()
		);
	}

	public function testUnloadedManyRelationTreatsCurrentObjectsAsAdded(): void
	{
		$item = new stdClass();
		$touched = $this->syncOne(RecordState::new($this->users()), [$item], false);

		self::assertSame([$item], $touched[0]->getAdded());
		self::assertSame([$item], $touched[0]->getItems());
		self::assertTrue($touched[0]->isPartiallyLoaded());
	}

	public function testPartiallyLoadedManyRelationTreatsCurrentObjectsAsAdded(): void
	{
		$item = new stdClass();
		$touched = $this->syncOne(RecordState::new($this->users()), [$item], false);

		self::assertSame([$item], $touched[0]->getAdded());
		self::assertSame([$item], $touched[0]->getItems());
		self::assertTrue($touched[0]->isPartiallyLoaded());
	}

	public function testFullyLoadedManyRelationAddsNewCurrentObjectsNotInKnownBaseline(): void
	{
		$known = new stdClass();
		$added = new stdClass();
		$owner = RecordState::new($this->users());
		$collection = ToManyRelationState::full($owner, 'posts', $this->postBinding(), [$known]);
		$toManyRelations = $this->toManyRelations($collection);

		$this->sync(
			$this->trackedMapWithRelated($this->trackedWithRelation($owner, ['posts' => [$known, $added]], true), $known, $added),
			$toManyRelations
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
		$collection = ToManyRelationState::full($owner, 'posts', $this->postBinding(), [$kept, $removed]);

		$this->sync(
			$this->trackedMapWithRelated($this->trackedWithRelation($owner, ['posts' => [$kept]], true), $kept),
			$this->toManyRelations($collection)
		);

		self::assertSame([], $collection->getAdded());
		self::assertSame([$removed], $collection->getRemoved());
		self::assertSame([$kept], $collection->getItems());
	}

	public function testFullyLoadedManyRelationKeepsUnchangedKnownObjectsUnchanged(): void
	{
		$known = new stdClass();
		$owner = RecordState::new($this->users());
		$collection = ToManyRelationState::full($owner, 'posts', $this->postBinding(), [$known]);

		$this->sync(
			$this->trackedMapWithRelated($this->trackedWithRelation($owner, ['posts' => [$known]], true), $known),
			$this->toManyRelations($collection)
		);

		self::assertSame([], $collection->getAdded());
		self::assertSame([], $collection->getRemoved());
		self::assertSame([$known], $collection->getItems());
	}

	public function testExistingToManyRelationStateInMapIsReused(): void
	{
		$owner = RecordState::new($this->users());
		$existing = new ToManyRelationState($owner, 'posts', $this->postBinding());
		$toManyRelations = $this->toManyRelations($existing);

		$touched = $this->sync(
			$this->trackedMapWithRelated($this->trackedWithRelation($owner, ['posts' => [$item = new stdClass()]]), $item),
			$toManyRelations
		);

		self::assertSame([$existing], $touched);
		self::assertSame($existing, $toManyRelations->get($owner, 'posts'));
	}

	public function testCreatedToManyRelationStateUsesOwnerStateFromRepresentationRelationStateItem(): void
	{
		$owner = RecordState::new($this->users());
		$touched = $this->syncOne($owner, []);

		self::assertSame($owner, $touched[0]->getOwner());
	}

	public function testCreatedToManyRelationStateUsesRelationNameFromRepresentationRelationBinding(): void
	{
		$touched = $this->syncOne(RecordState::new($this->users()), []);

		self::assertSame('posts', $touched[0]->getRelationName());
	}

	public function testCreatedToManyRelationStateUsesRelatedBindingFromRepresentationRelationBinding(): void
	{
		$owner = RecordState::new($this->users());
		$relatedBinding = $this->postBinding();
		$binding = new RepresentationBinding($this->users());
		$binding->addRelation(new RepresentationRelationBinding(
			'posts',
			$owner->getCollection(),
			'posts',
			$relatedBinding
		));

		$touched = $this->sync(
			$this->representations($this->tracked($this->representation(['posts' => []]), $binding, [$owner])),
			new RelationStateStore()
		);

		self::assertSame($relatedBinding, $touched[0]->getRelatedBinding());
	}

	public function testTouchedCollectionsAreReturnedOnceEvenIfReachedTwice(): void
	{
		$item = new stdClass();
		$owner = RecordState::new($this->users());

		$touched = $this->sync(
			$this->trackedMapWithRelated(
				$this->trackedWithRelation($owner, ['posts' => [$item]]),
				$this->trackedWithRelation($owner, ['posts' => [$item]]),
				$item
			),
			new RelationStateStore()
		);

		self::assertCount(1, $touched);
		self::assertSame([$item], $touched[0]->getAdded());
	}

	public function testManyRelationWithUntrackedItemThrowsSyncException(): void
	{
		$item = new stdClass();
		$representations = $this->representations($this->trackedWithRelation(RecordState::new($this->users()), ['posts' => [$item]]));

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('posts');
		$this->expectExceptionMessage('not tracked');

		$this->sync($representations, new RelationStateStore());
	}

	public function testUntrackedRelatedObjectExceptionMessageIncludesRelationPath(): void
	{
		$post = RecordState::new($this->posts());
		$target = new stdClass();
		$author = new stdClass();
		$author->profile = $target;
		$representation = new stdClass();
		$representation->author = $author;
		$binding = new RepresentationBinding($this->posts());
		$binding->addRelation(new RepresentationRelationBinding(
			'author.profile',
			$this->posts(),
			'author',
			$this->profileBinding()
		));

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('author.profile');

		$this->sync($this->representations($this->tracked($representation, $binding, [$post])));
	}

	public function testUntrackedRelatedObjectDoesNotAutoAdoptRepresentationStates(): void
	{
		$target = new stdClass();
		$representations = $this->representations($this->trackedWithOneRelation(RecordState::new($this->users()), ['profile' => $target]));

		try {
			$this->sync($representations);
		} catch (SyncException) {
		}

		self::assertNull($representations->get($target));
	}

	public function testRelationSynchronizerDoesNotExecuteCommandsOrCallRelationPersistencePlanners(): void
	{
		$source = file_get_contents(__DIR__ . '/../../../src/ORM/Sync/RelationRepresentationSynchronizer.php');

		self::assertIsString($source);
		self::assertStringNotContainsString('CommandExecutor', $source);
		self::assertStringNotContainsString('CommandInterface', $source);
		self::assertStringNotContainsString('RelationPersistencePlanner', $source);
	}

	/**
	 * @param list<object> $items
	 * @return list<ToManyRelationState>
	 */
	private function syncOne(
		RecordState $owner,
		array $items,
		bool $fullyLoaded = false,
	): array {
		$tracked = $this->trackedWithRelation($owner, ['posts' => $items], $fullyLoaded);

		return $this->sync(
			$this->trackedMapWithRelated($tracked, ...$items),
			new RelationStateStore()
		);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function trackedWithRelation(
		RecordState $owner,
		array $values,
		bool $fullyLoaded = false,
	): RepresentationState {
		$binding = new RepresentationBinding($this->users());
		$binding->addRelation($this->relationBinding($owner, RepresentationRelationCardinality::MANY, $fullyLoaded));

		return $this->tracked($this->representation($values), $binding, [$owner]);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function trackedWithOneRelation(RecordState $owner, array $values): RepresentationState
	{
		$binding = new RepresentationBinding($this->users());
		$binding->addRelation($this->relationBinding($owner, RepresentationRelationCardinality::ONE));

		return $this->tracked($this->representation($values), $binding, [$owner]);
	}

	private function relationBinding(
		RecordState $owner,
		RepresentationRelationCardinality $cardinality,
		bool $fullyLoaded = false,
	): RepresentationRelationBinding {
		if ($cardinality === RepresentationRelationCardinality::ONE) {
			return new RepresentationRelationBinding(
				'profile',
				$owner->getCollection(),
				'profile',
				$this->profileBinding(),
			);
		}

		return new RepresentationRelationBinding(
			'posts',
			$owner->getCollection(),
			'posts',
			$this->postBinding(),
		);
	}

	private function trackedMapWithRelated(object ...$entries): RepresentationStateStore
	{
		$map = new RepresentationStateStore();
		foreach ($entries as $entry) {
			if ($entry instanceof RepresentationState) {
				RepresentationStateObjectRegistry::addTo($map, $entry);

				continue;
			}

			if (! $map->has($entry)) {
				$map->add($entry, $this->tracked($entry, new RepresentationBinding($this->posts())));
			}
		}

		return $map;
	}

	private function toManyRelations(ToManyRelationState ...$collections): RelationStateStore
	{
		$map = new RelationStateStore();
		foreach ($collections as $collection) {
			$map->add($collection);
		}

		return $map;
	}

	private function toOneRelations(ToOneRelationState ...$references): RelationStateStore
	{
		$map = new RelationStateStore();
		foreach ($references as $reference) {
			$map->add($reference);
		}

		return $map;
	}

	private function synchronizer(): RelationRepresentationSynchronizer
	{
		return new RelationRepresentationSynchronizer();
	}

	/**
	 * @return list<RelationChangeInterface>
	 */
	private function sync(
		RepresentationStateStore $representations,
		?RelationStateStore $toManyRelations = null,
		?RelationStateStore $toOneRelations = null,
	): array {
		return $this->synchronizer()->sync(
			$representations,
			$toManyRelations ?? new RelationStateStore(),
			$toOneRelations ?? new RelationStateStore()
		);
	}
}
