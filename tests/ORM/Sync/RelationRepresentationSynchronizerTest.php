<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\Definition\Relation\RelationCardinality;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Relation\RelationStateInterface;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\RepresentationRelationSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\State\RepresentationStateStore;
use ON\Data\ORM\Representation\Sync\RelationRepresentationSynchronizer;
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

	public function testIgnoresSchemasWithNoRelationSchemas(): void
	{
		$owner = RecordState::new($this->users());
		$schema = new RepresentationSchema($this->users());
		$schema->addField(new RepresentationFieldSchema('name', $owner->getCollection(), 'name'));
		$manyStates = new RelationStateStore();

		$touched = $this->sync(
			$this->representations($this->tracked($this->representation(['name' => 'Ada']), $schema)),
			$manyStates
		);

		self::assertSame([], $touched);
		self::assertSame([], $manyStates->getAll());
	}

	public function testOneRelationWithNullValueCreatesTouchedReferenceWithNoTarget(): void
	{
		$owner = RecordState::new($this->users());
		$schema = new RepresentationSchema($this->users());
		$schema->addRelation($this->relationSchema($owner, RelationCardinality::SINGLE));
		$relations = new RelationStateStore();

		$touched = $this->sync(
			$this->representations($this->tracked($this->representation(['profile' => null]), $schema, [$owner])),
			$relations
		);

		self::assertCount(1, $touched);
		self::assertInstanceOf(ToOneRelationState::class, $touched[0]);
		self::assertFalse($touched[0]->hasTarget());
		self::assertFalse($touched[0]->hasChanges());
		self::assertSame($touched[0], $relations->get($owner, 'profile'));
	}

	public function testOneRelationWithObjectValueCreatesTouchedReferenceAndSetsTarget(): void
	{
		$owner = RecordState::new($this->users());
		$target = new stdClass();
		$oneStates = new RelationStateStore();

		$touched = $this->sync(
			$this->trackedMapWithRelated($this->trackedWithOneRelation($owner, ['profile' => $target]), $target),
			oneStates: $oneStates
		);

		self::assertCount(1, $touched);
		self::assertInstanceOf(ToOneRelationState::class, $touched[0]);
		self::assertSame($target, $touched[0]->getTarget());
		self::assertTrue($touched[0]->hasChanges());
		self::assertSame($touched[0], $oneStates->get($owner, 'profile'));
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
		$existing = new ToOneRelationState($owner, 'profile', $this->profileSchema());
		$oneStates = $this->oneStates($existing);

		$touched = $this->sync(
			$this->trackedMapWithRelated($this->trackedWithOneRelation($owner, ['profile' => $target]), $target),
			oneStates: $oneStates
		);

		self::assertSame([$existing], $touched);
		self::assertSame($target, $existing->getTarget());
		self::assertSame($existing, $oneStates->get($owner, 'profile'));
	}

	public function testTouchedChangesIncludeCollectionAndReferenceOnceEach(): void
	{
		$owner = RecordState::new($this->users());
		$item = new stdClass();
		$target = new stdClass();
		$schema = new RepresentationSchema($this->users());
		$schema->addRelation($this->relationSchema($owner, RelationCardinality::MANY));
		$schema->addRelation($this->relationSchema($owner, RelationCardinality::SINGLE));

		$touched = $this->sync(
			$this->trackedMapWithRelated(
				$this->tracked($this->representation(['posts' => [$item], 'profile' => $target]), $schema, [$owner]),
				$this->tracked($this->representation(['posts' => [$item], 'profile' => $target]), $schema, [$owner]),
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
		$manyStates = new RelationStateStore();

		$touched = $this->sync(
			$this->representations($this->trackedWithRelation($owner, ['posts' => null])),
			$manyStates
		);

		self::assertCount(1, $touched);
		self::assertSame([], $touched[0]->getAdded());
		self::assertSame([], $touched[0]->getItems());
		self::assertSame($touched[0], $manyStates->get($owner, 'posts'));
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
		$collection = ToManyRelationState::full($owner, 'posts', $this->postSchema(), [$known]);
		$manyStates = $this->manyStates($collection);

		$this->sync(
			$this->trackedMapWithRelated($this->trackedWithRelation($owner, ['posts' => [$known, $added]], true), $known, $added),
			$manyStates
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
		$collection = ToManyRelationState::full($owner, 'posts', $this->postSchema(), [$kept, $removed]);

		$this->sync(
			$this->trackedMapWithRelated($this->trackedWithRelation($owner, ['posts' => [$kept]], true), $kept),
			$this->manyStates($collection)
		);

		self::assertSame([], $collection->getAdded());
		self::assertSame([$removed], $collection->getRemoved());
		self::assertSame([$kept], $collection->getItems());
	}

	public function testFullyLoadedManyRelationKeepsUnchangedKnownObjectsUnchanged(): void
	{
		$known = new stdClass();
		$owner = RecordState::new($this->users());
		$collection = ToManyRelationState::full($owner, 'posts', $this->postSchema(), [$known]);

		$this->sync(
			$this->trackedMapWithRelated($this->trackedWithRelation($owner, ['posts' => [$known]], true), $known),
			$this->manyStates($collection)
		);

		self::assertSame([], $collection->getAdded());
		self::assertSame([], $collection->getRemoved());
		self::assertSame([$known], $collection->getItems());
	}

	public function testExistingToManyRelationStateInMapIsReused(): void
	{
		$owner = RecordState::new($this->users());
		$existing = new ToManyRelationState($owner, 'posts', $this->postSchema());
		$manyStates = $this->manyStates($existing);

		$touched = $this->sync(
			$this->trackedMapWithRelated($this->trackedWithRelation($owner, ['posts' => [$item = new stdClass()]]), $item),
			$manyStates
		);

		self::assertSame([$existing], $touched);
		self::assertSame($existing, $manyStates->get($owner, 'posts'));
	}

	public function testCreatedToManyRelationStateUsesOwnerStateFromRepresentationRelationStateItem(): void
	{
		$owner = RecordState::new($this->users());
		$touched = $this->syncOne($owner, []);

		self::assertSame($owner, $touched[0]->getOwner());
	}

	public function testCreatedToManyRelationStateUsesRelationNameFromRepresentationRelationSchema(): void
	{
		$touched = $this->syncOne(RecordState::new($this->users()), []);

		self::assertSame('posts', $touched[0]->getRelationName());
	}

	public function testCreatedToManyRelationStateUsesRelatedSchemaFromRepresentationRelationSchema(): void
	{
		$owner = RecordState::new($this->users());
		$relatedSchema = $this->postSchema();
		$schema = new RepresentationSchema($this->users());
		$schema->addRelation(new RepresentationRelationSchema(
			'posts',
			$owner->getCollection(),
			'posts',
			$relatedSchema
		));

		$touched = $this->sync(
			$this->representations($this->tracked($this->representation(['posts' => []]), $schema, [$owner])),
			new RelationStateStore()
		);

		self::assertSame($relatedSchema, $touched[0]->getRelatedSchema());
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
		$schema = new RepresentationSchema($this->posts());
		$schema->addRelation(new RepresentationRelationSchema(
			'author.profile',
			$this->posts(),
			'author',
			$this->profileSchema()
		));

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('author.profile');

		$this->sync($this->representations($this->tracked($representation, $schema, [$post])));
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
		$source = file_get_contents(__DIR__ . '/../../../src/ORM/Representation/Sync/RelationRepresentationSynchronizer.php');

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
		$schema = new RepresentationSchema($this->users());
		$schema->addRelation($this->relationSchema($owner, RelationCardinality::MANY, $fullyLoaded));

		return $this->tracked($this->representation($values), $schema, [$owner]);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function trackedWithOneRelation(RecordState $owner, array $values): RepresentationState
	{
		$schema = new RepresentationSchema($this->users());
		$schema->addRelation($this->relationSchema($owner, RelationCardinality::SINGLE));

		return $this->tracked($this->representation($values), $schema, [$owner]);
	}

	private function relationSchema(
		RecordState $owner,
		RelationCardinality $cardinality,
		bool $fullyLoaded = false,
	): RepresentationRelationSchema {
		if ($cardinality->isSingle()) {
			return new RepresentationRelationSchema(
				'profile',
				$owner->getCollection(),
				'profile',
				$this->profileSchema(),
			);
		}

		return new RepresentationRelationSchema(
			'posts',
			$owner->getCollection(),
			'posts',
			$this->postSchema(),
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
				$map->add($entry, $this->tracked($entry, new RepresentationSchema($this->posts())));
			}
		}

		return $map;
	}

	private function manyStates(ToManyRelationState ...$collections): RelationStateStore
	{
		$map = new RelationStateStore();
		foreach ($collections as $collection) {
			$map->add($collection);
		}

		return $map;
	}

	private function oneStates(ToOneRelationState ...$references): RelationStateStore
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
	 * @return list<RelationStateInterface>
	 */
	private function sync(
		RepresentationStateStore $representations,
		?RelationStateStore $manyStates = null,
		?RelationStateStore $oneStates = null,
	): array {
		$relations = $manyStates ?? $oneStates ?? new RelationStateStore();
		if ($oneStates !== null && $oneStates !== $relations) {
			foreach ($oneStates->getAll() as $relation) {
				$relations->add($relation);
			}
		}

		return $this->synchronizer()->sync(
			$representations,
			$relations
		);
	}
}
