<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Compiler\ProjectionSourceBuilder;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\Sync\RepresentationStateFactory;
use PHPUnit\Framework\TestCase;

final class RepresentationStateFactoryTest extends TestCase
{
	public function testCreatesStateFromRootRecordWithFieldAndRelationItems(): void
	{
		$registry = $this->registry();
		$users = $registry->getCollection('users');
		$posts = $registry->getCollection('posts');
		$record = RecordState::new($users, ['id' => 1, 'name' => 'Ada']);
		$record->setValue('name', 'Ada Lovelace');
		$postSchema = new RepresentationBinding($posts);
		$schema = new RepresentationBinding($users);
		$schema->addField(new RepresentationFieldBinding('name', $users, 'name'));
		$schema->addRelation(new RepresentationRelationBinding('posts', $users, 'posts', $postSchema));

		$state = (new RepresentationStateFactory())->fromRootRecord($schema, $record);

		self::assertSame($schema, $state->getBinding());
		self::assertSame($record, $state->getFieldItem('name')->getRecord());
		self::assertSame(2, $state->getFieldItem('name')->getBaselineRevision());
		self::assertSame($record, $state->getRelationItem('posts')->getOwnerRecord());
		self::assertSame('posts', $state->getRelationItem('posts')->getRelationName());
	}

	public function testRootRecordRejectsFieldFromAnotherCollection(): void
	{
		$registry = $this->registry();
		$users = $registry->getCollection('users');
		$posts = $registry->getCollection('posts');
		$schema = new RepresentationBinding($posts);
		$schema->addField(new RepresentationFieldBinding('title', $posts, 'title'));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage("targets collection 'posts', not 'users'");

		(new RepresentationStateFactory())->fromRootRecord($schema, RecordState::new($users));
	}

	public function testCreatesStateFromProjectionSourceRecords(): void
	{
		$registry = $this->registry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$userRecord = RecordState::clean($users->getKey(1), ['id' => 1]);
		$companyRecord = RecordState::clean($companies->getKey(5), ['id' => 5, 'name' => 'Acme']);
		$companyRecord->setValue('name', 'Acme Ltd');
		$schema = $this->projectionSchema($users, $companies);
		$sources = (new ProjectionSourceBuilder())->build($schema);

		$state = (new RepresentationStateFactory())->fromSourceRecords($schema, $sources, [
			'' => $userRecord,
			'company' => $companyRecord,
		]);

		self::assertSame($userRecord, $state->getFieldItem('id')->getRecord());
		self::assertSame($companyRecord, $state->getFieldItem('companyName')->getRecord());
		self::assertSame(2, $state->getFieldItem('companyName')->getBaselineRevision());
	}

	public function testProjectionSourceRecordsRejectRecordFromAnotherCollection(): void
	{
		$registry = $this->registry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$schema = $this->projectionSchema($users, $companies);
		$sources = (new ProjectionSourceBuilder())->build($schema);

		$this->expectException(StateException::class);
		$this->expectExceptionMessage("Representation source path 'company' targets collection 'companies', not 'users'.");

		(new RepresentationStateFactory())->fromSourceRecords($schema, $sources, [
			'' => RecordState::clean($users->getKey(1), ['id' => 1]),
			'company' => RecordState::clean($users->getKey(2), ['id' => 2]),
		]);
	}

	public function testProjectionSourceRecordsRequireResolvedSourcePath(): void
	{
		$registry = $this->registry();
		$users = $registry->getCollection('users');
		$companies = $registry->getCollection('companies');
		$schema = $this->projectionSchema($users, $companies);
		$sources = (new ProjectionSourceBuilder())->build($schema);

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('source path "company" is unresolved');

		(new RepresentationStateFactory())->fromSourceRecords($schema, $sources, [
			'' => RecordState::clean($users->getKey(1), ['id' => 1]),
		]);
	}

	public function testProjectionSourceRecordsRejectRelationBindings(): void
	{
		$registry = $this->registry();
		$users = $registry->getCollection('users');
		$posts = $registry->getCollection('posts');
		$schema = new RepresentationBinding($users);
		$schema->addRelation(new RepresentationRelationBinding('posts', $users, 'posts', new RepresentationBinding($posts)));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('binding contains relation bindings');

		(new RepresentationStateFactory())->fromSourceRecords($schema, [], []);
	}

	private function projectionSchema(
		CollectionInterface $users,
		CollectionInterface $companies,
	): RepresentationBinding {
		$schema = new RepresentationBinding($users);
		$schema->addField(new RepresentationFieldBinding('id', $users, 'id', writable: false));
		$schema->addField(new RepresentationFieldBinding('companyName', $companies, 'name', sourcePath: ['company']));

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
