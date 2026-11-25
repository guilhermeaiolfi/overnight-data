<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\Schema\RepresentationFieldSchema;
use ON\Data\Schema\RepresentationRelationSchema;
use ON\Data\Schema\RepresentationSchema;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class RepresentationStateTest extends TestCase
{
	use OrmFixture;

	public function testStoresSchemaAndResolvesBoundFields(): void
	{
		$users = $this->users();
		$schema = new RepresentationSchema($users);
		$field = new RepresentationFieldSchema('name', $users, 'name');
		$schema->addField($field);
		$record = RecordState::new($users, ['name' => 'A1']);
		$state = RepresentationState::fromRecords($schema, ['' => $record]);

		self::assertSame($schema, $state->getSchema());
		self::assertTrue($state->hasRecordForField($field));
		self::assertSame($record, $state->getRecordForField($field));
		self::assertSame([$field], $state->getWritableFields());
		self::assertSame($record, $state->getRootRecord());
		self::assertSame($record, $state->requireRootRecord());
	}

	public function testGetRecordForFieldThrowsWhenOptionalSourceIsUnbound(): void
	{
		$schema = new RepresentationSchema($this->users());
		$posts = $this->posts();
		$field = new RepresentationFieldSchema('title', $posts, 'title', sourcePath: ['posts']);
		$schema->addField($field);
		$state = RepresentationState::fromRecords($schema, []);

		$this->expectException(StateException::class);
		$state->getRecordForField($field);
	}

	public function testGetRootRecordReturnsNullWhenNoRootSourceIsBound(): void
	{
		$state = RepresentationState::fromRecords(new RepresentationSchema($this->users()), []);

		self::assertNull($state->getRootRecord());
	}

	public function testRequireRootRecordThrowsWhenNoRootSourceIsBound(): void
	{
		$state = RepresentationState::fromRecords(new RepresentationSchema($this->users()), []);

		$this->expectException(StateException::class);
		$state->requireRootRecord();
	}

	public function testGetRootRecordUsesRootSourceEvenWhenRelatedSourceHasSameCollection(): void
	{
		$users = $this->users();
		$root = RecordState::new($users, ['name' => 'A1']);
		$manager = RecordState::new($users, ['name' => 'B1']);
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('managerName', $users, 'name', sourcePath: ['manager']));
		$schema->addField(new RepresentationFieldSchema('name', $users, 'name'));

		$state = RepresentationState::fromRecords($schema, ['manager' => $manager, '' => $root]);

		self::assertSame($root, $state->getRootRecord());
	}

	public function testRelationOwnerMustMatchSchemaCollection(): void
	{
		$users = $this->users();
		$posts = $this->posts();
		$schema = new RepresentationSchema($users);
		$schema->addRelation(new RepresentationRelationSchema('posts', $users, 'posts', new RepresentationSchema($posts)));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage("Representation relation path 'posts' targets collection 'users', not 'posts'.");
		RepresentationState::fromRecords($schema, ['' => RecordState::new($posts)]);
	}

	public function testBaselineSnapshotPreservesNullAndMissingPathsAcrossRestore(): void
	{
		$users = $this->users();
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('name', $users, 'name'));
		$schema->addField(new RepresentationFieldSchema('email', $users, 'email'));
		$state = RepresentationState::fromRecords($schema, ['' => RecordState::new($users, ['name' => null])]);
		$snapshot = $state->snapshotBaselines();

		$state->acceptSyncedValues(['email' => 'a@example.test']);
		self::assertSame(['name' => null, 'email' => 'a@example.test'], $state->snapshotBaselines());

		$state->restoreBaselines($snapshot);
		self::assertTrue($state->hasBaseline('name'));
		self::assertNull($state->getBaseline('name'));
		self::assertFalse($state->hasBaseline('email'));
	}
}
