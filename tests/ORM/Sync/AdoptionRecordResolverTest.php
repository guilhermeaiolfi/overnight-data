<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\Sync\AdoptionRecordResolver;
use ON\Data\ORM\Representation\Sync\RepresentationIntentLifecycle;
use ON\Data\ORM\Representation\Sync\RepresentationIntentStore;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class AdoptionRecordResolverTest extends TestCase
{
	use OrmFixture;

	public function testCreatesNewRecordStateWhenPrimaryKeyCannotBeCompleted(): void
	{
		$representation = $this->representation(['id' => null, 'name' => 'Ada']);
		$records = new RecordStateStore();

		$record = $this->resolver()->resolve($representation, $this->userSchemaWithId(), $records, true);

		self::assertTrue($record->isNew());
		self::assertSame('Ada', $record->getValue('name'));
		self::assertFalse($record->hasKey());
	}

	public function testCreatesNewRecordStateWhenRelatedSchemaHasCompletePrimaryKey(): void
	{
		$representation = $this->representation(['id' => 10, 'name' => 'Ada']);
		$records = new RecordStateStore();

		$record = $this->resolver()->resolve($representation, $this->userSchemaWithId(), $records, false);

		self::assertTrue($record->isNew());
		self::assertFalse($record->hasKey());
		self::assertSame(10, $record->getValue('id'));
		self::assertSame('Ada', $record->getValue('name'));
	}

	public function testCreatesNewRecordStateWhenRootHasCompletePrimaryKeyWithoutExistingIntent(): void
	{
		$representation = $this->representation(['id' => 10, 'name' => 'Ada']);
		$records = new RecordStateStore();

		$record = $this->resolver()->resolve($representation, $this->userSchemaWithId(), $records, true);

		self::assertTrue($record->isNew());
		self::assertFalse($record->hasKey());
		self::assertSame(10, $record->getValue('id'));
		self::assertSame('Ada', $record->getValue('name'));
	}

	public function testPatchesExistingRecordWhenUpdateIntentAndPrimaryKeyCanBeCompleted(): void
	{
		$representation = $this->representation(['id' => 10, 'name' => 'Ada']);
		$records = new RecordStateStore();
		$intents = new RepresentationIntentStore();
		$intents->ensure($representation, RepresentationIntentLifecycle::Update);

		$record = (new AdoptionRecordResolver(intents: $intents))
			->resolve($representation, $this->userSchemaWithId(), $records, true);

		self::assertTrue($record->isDirty());
		self::assertSame(10, $record->getKey()?->getFieldValue('id'));
		self::assertSame('Ada', $record->getValue('name'));
		self::assertSame(['name'], $record->getDirtyFields());
	}

	public function testUsesIntentIdentityWhenPrimaryKeyIsNotOnRepresentation(): void
	{
		$representation = $this->representation(['name' => 'Ada']);
		$schema = new RepresentationSchema($this->users());
		$schema->addField(new RepresentationFieldSchema('name', $this->users(), 'name'));
		$records = new RecordStateStore();
		$intents = new RepresentationIntentStore();
		$intents->ensure($representation, RepresentationIntentLifecycle::Update)
			->setIdentity(['id' => 10]);

		$record = (new AdoptionRecordResolver(intents: $intents))
			->resolve($representation, $schema, $records, true);

		self::assertTrue($record->isDirty());
		self::assertSame(10, $record->getKey()?->getFieldValue('id'));
		self::assertSame('Ada', $record->getValue('name'));
	}

	public function testRejectsIntentIdentityThatDisagreesWithRepresentationPrimaryKey(): void
	{
		$representation = $this->representation(['id' => 11, 'name' => 'Ada']);
		$intents = new RepresentationIntentStore();
		$intents->ensure($representation, RepresentationIntentLifecycle::Update)
			->setIdentity(['id' => 10]);

		$this->expectException(StateException::class);
		$this->expectExceptionMessage("intent identity field 'id' disagrees with the representation");

		(new AdoptionRecordResolver(intents: $intents))
			->resolve($representation, $this->userSchemaWithId(), new RecordStateStore(), true);
	}

	public function testReturnsExistingTrackedRecordStateWhenKeyIsAlreadyTracked(): void
	{
		$representation = $this->representation(['id' => 10, 'name' => 'Ada']);
		$existing = RecordState::clean($this->users()->getKey(10), ['id' => 10, 'name' => 'Existing']);
		$records = $this->records($existing);

		$record = $this->resolver()->resolve($representation, $this->userSchemaWithId(), $records, true);

		self::assertSame($existing, $record);
		self::assertSame('Existing', $record->getValue('name'));
	}

	public function testRejectsAdoptingRepresentationForRemovedTrackedRecord(): void
	{
		$representation = $this->representation(['id' => 10, 'name' => 'Ada']);
		$existing = RecordState::clean($this->users()->getKey(10), ['id' => 10, 'name' => 'Removed']);
		$existing->markRemoved();
		$records = $this->records($existing);

		$this->expectException(StateException::class);
		$this->expectExceptionMessage("Cannot adopt representation for collection 'users' because key 'users#id=10' is already tracked as removed.");

		$this->resolver()->resolve($representation, $this->userSchemaWithId(), $records, true);
	}

	public function testRejectsRootSchemaWithNoTargetCollection(): void
	{
		$this->expectException(StateException::class);
		$this->expectExceptionMessage('untracked root sync needs a schema targeting one collection');

		$this->resolver()->resolve(new stdClass(), new RepresentationSchema($this->users()), new RecordStateStore(), true);
	}

	public function testRejectsRelatedSchemaWithNoTargetCollection(): void
	{
		$this->expectException(StateException::class);
		$this->expectExceptionMessage('related schema does not target a collection');

		$this->resolver()->resolve(new stdClass(), new RepresentationSchema($this->users()), new RecordStateStore(), false);
	}

	public function testRejectsSchemaTargetingMultipleCollectionNames(): void
	{
		$schema = new RepresentationSchema($this->users());
		$schema->addField(new RepresentationFieldSchema('name', $this->users(), 'name'));
		$schema->addField(new RepresentationFieldSchema('title', $this->posts(), 'title'));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage("path 'title' targets collection 'posts' after 'users'");

		$this->resolver()->resolve(new stdClass(), $schema, new RecordStateStore(), true);
	}

	public function testIgnoresMissingNonKeyPathsWhenBuildingInitialValues(): void
	{
		$schema = new RepresentationSchema($this->users());
		$schema->addField(new RepresentationFieldSchema('id', $this->users(), 'id'));
		$schema->addField(new RepresentationFieldSchema('name', $this->users(), 'name'));
		$representation = $this->representation(['id' => 10]);
		$intents = new RepresentationIntentStore();
		$intents->ensure($representation, RepresentationIntentLifecycle::Update);

		$record = (new AdoptionRecordResolver(intents: $intents))
			->resolve($representation, $schema, new RecordStateStore(), true);

		self::assertTrue($record->isClean());
		self::assertSame(['id' => 10], $record->getValues());
	}

	public function testTreatsMissingKeyPathAsIncompleteKey(): void
	{
		$representation = $this->representation(['name' => 'Ada']);
		$schema = new RepresentationSchema($this->users());
		$schema->addField(new RepresentationFieldSchema('name', $this->users(), 'name'));

		$record = $this->resolver()->resolve($representation, $schema, new RecordStateStore(), true);

		self::assertTrue($record->isNew());
		self::assertFalse($record->hasKey());
	}

	public function testTreatsNullKeyPathAsIncompleteKey(): void
	{
		$representation = $this->representation(['id' => null, 'name' => 'Ada']);

		$record = $this->resolver()->resolve($representation, $this->userSchemaWithId(), new RecordStateStore(), true);

		self::assertTrue($record->isNew());
		self::assertSame('Ada', $record->getValue('name'));
	}

	public function testRelatedSchemaRejectsMultipleCollectionNames(): void
	{
		$schema = new RepresentationSchema($this->users());
		$schema->addField(new RepresentationFieldSchema('name', $this->users(), 'name'));
		$schema->addField(new RepresentationFieldSchema('title', $this->posts(), 'title'));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage("related schema path 'title' targets collection 'posts' after 'users'");

		$this->resolver()->resolve(new stdClass(), $schema, new RecordStateStore(), false);
	}

	private function resolver(): AdoptionRecordResolver
	{
		return new AdoptionRecordResolver();
	}
}
