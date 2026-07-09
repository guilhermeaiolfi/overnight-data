<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationFieldSchema;
use ON\Data\ORM\State\RepresentationRelationSchema;
use ON\Data\ORM\State\RepresentationSchema;
use ON\Data\ORM\State\RepresentationState;
use PHPUnit\Framework\TestCase;

final class RepresentationStateFromRecordsTest extends TestCase
{
	public function testCreatesStateFromSingleRootRecord(): void
	{
		$registry = $this->registry();
		$users = $registry->getCollection('users');
		$record = RecordState::new($users, ['id' => 1, 'name' => 'Ada']);
		$record->setValue('name', 'Ada Lovelace');
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('name', $users, 'name'));

		$state = RepresentationState::fromRecords($schema, [
			RepresentationFieldSchema::sourcePathKey([]) => $record,
		]);

		self::assertSame($schema, $state->getSchema());
		self::assertSame($record, $state->getFieldItem('name')->getRecord());
		self::assertSame(2, $state->getFieldItem('name')->getBaselineRevision());
	}

	public function testCreatesStateFromMultipleSourceRecords(): void
	{
		$registry = $this->registry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$userRecord = RecordState::clean($users->getKey(1), ['id' => 1]);
		$companyRecord = RecordState::clean($companies->getKey(5), ['id' => 5, 'name' => 'Acme']);
		$companyRecord->setValue('name', 'Acme Ltd');
		$schema = $this->projectionSchema($users, $companies);

		$state = RepresentationState::fromRecords($schema, [
			RepresentationFieldSchema::sourcePathKey([]) => $userRecord,
			RepresentationFieldSchema::sourcePathKey(['company']) => $companyRecord,
		]);

		self::assertSame($userRecord, $state->getFieldItem('id')->getRecord());
		self::assertSame($companyRecord, $state->getFieldItem('companyName')->getRecord());
		self::assertSame(2, $state->getFieldItem('companyName')->getBaselineRevision());
	}

	public function testRejectsMissingRequiredSourceRecord(): void
	{
		$registry = $this->registry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$schema = $this->projectionSchema($users, $companies);

		$this->expectException(StateException::class);
		$this->expectExceptionMessage("source path 'company' is unresolved");

		RepresentationState::fromRecords($schema, [
			RepresentationFieldSchema::sourcePathKey([]) => RecordState::clean($users->getKey(1), ['id' => 1]),
		]);
	}

	public function testRespectsSkipWhenMissingFromFieldSchema(): void
	{
		$registry = $this->registry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('id', $users, 'id', writable: false));
		$schema->addField((new RepresentationFieldSchema('companyName', $companies, 'name', sourcePath: ['company']))->withSkipWhenMissing(true));

		$state = RepresentationState::fromRecords($schema, [
			RepresentationFieldSchema::sourcePathKey([]) => RecordState::clean($users->getKey(1), ['id' => 1]),
		]);

		self::assertTrue($state->hasFieldItem('id'));
		self::assertFalse($state->hasFieldItem('companyName'));
	}

	public function testRejectsSourceRecordWithWrongCollection(): void
	{
		$registry = $this->registry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$schema = $this->projectionSchema($users, $companies);

		$this->expectException(StateException::class);
		$this->expectExceptionMessage("Representation schema path 'companyName' targets collection 'companies', not 'users'.");

		RepresentationState::fromRecords($schema, [
			RepresentationFieldSchema::sourcePathKey([]) => RecordState::clean($users->getKey(1), ['id' => 1]),
			RepresentationFieldSchema::sourcePathKey(['company']) => RecordState::clean($users->getKey(2), ['id' => 2]),
		]);
	}

	public function testCreatesRelationStateItemsFromRootRecord(): void
	{
		$registry = $this->registry();
		$users = $registry->getCollection('users');
		$posts = $registry->getCollection('posts');
		$record = RecordState::new($users, ['id' => 1, 'name' => 'Ada']);
		$postSchema = new RepresentationSchema($posts);
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('name', $users, 'name'));
		$schema->addRelation(new RepresentationRelationSchema('posts', $users, 'posts', $postSchema));

		$state = RepresentationState::fromRecords($schema, [
			RepresentationFieldSchema::sourcePathKey([]) => $record,
		]);

		self::assertSame($record, $state->getRelationItem('posts')->getOwnerRecord());
		self::assertSame('posts', $state->getRelationItem('posts')->getRelationName());
	}

	public function testRelationOnlySchemaRequiresRootRecord(): void
	{
		$registry = $this->registry();
		$users = $registry->getCollection('users');
		$posts = $registry->getCollection('posts');
		$schema = new RepresentationSchema($users);
		$schema->addRelation(new RepresentationRelationSchema('posts', $users, 'posts', new RepresentationSchema($posts)));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage("root source path '' is unresolved");

		RepresentationState::fromRecords($schema, []);
	}

	public function testRelationOnlySchemaCreatesRelationItemsFromRootRecord(): void
	{
		$registry = $this->registry();
		$users = $registry->getCollection('users');
		$posts = $registry->getCollection('posts');
		$record = RecordState::new($users, ['id' => 1, 'name' => 'Ada']);
		$schema = new RepresentationSchema($users);
		$schema->addRelation(new RepresentationRelationSchema('posts', $users, 'posts', new RepresentationSchema($posts)));

		$state = RepresentationState::fromRecords($schema, [
			RepresentationFieldSchema::sourcePathKey([]) => $record,
		]);

		self::assertSame($record, $state->getRelationItem('posts')->getOwnerRecord());
	}

	private function projectionSchema(
		CollectionInterface $users,
		CollectionInterface $companies,
	): RepresentationSchema {
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('id', $users, 'id', writable: false));
		$schema->addField(new RepresentationFieldSchema('companyName', $companies, 'name', sourcePath: ['company']));

		return $schema;
	}

	private function registry(): Registry
	{
		$registry = new Registry();

		$registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('company_id', 'int')->end()
			->field('name', 'string')->end();

		$registry->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end();

		$registry->collection('companies')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		$registry->getCollection('users')
			->hasMany('posts', 'posts');
		$registry->getCollection('users')
			->belongsTo('company', 'companies')
			->innerKey('company_id')
			->outerKey('id');

		return $registry;
	}
}
