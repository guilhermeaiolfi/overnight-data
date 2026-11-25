<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\Definition\Registry;
use ON\Data\ORM\Record\RecordLifecycle;
use ON\Data\ORM\Representation\Sync\AdoptionPolicy;
use ON\Data\ORM\Representation\Sync\RepresentationAdoptionContext;
use ON\Data\ORM\Representation\Sync\RepresentationAdoptionEngine;
use ON\Data\ORM\Representation\Sync\RepresentationIntent;
use ON\Data\ORM\Representation\Sync\RepresentationIntentLifecycle;
use ON\Data\ORM\Representation\Sync\RepresentationIntentStore;
use ON\Data\ORM\SessionContext;
use ON\Data\Schema\RepresentationFieldSchema;
use ON\Data\Schema\RepresentationSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RepresentationAdoptionEngineTest extends TestCase
{
	#[DataProvider('rootIntentPolicies')]
	public function testRootIntentPrecedenceDoesNotOverrideFlatDefaults(
		AdoptionPolicy $policy,
		?RepresentationIntentLifecycle $storedLifecycle,
		?RepresentationIntentLifecycle $contextLifecycle,
		RecordLifecycle $expectedRoot,
		RecordLifecycle $expectedRelated,
	): void {
		$users = $this->makeSelfRelationRegistry()->getCollection('users');
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('id', $users, 'id'));
		$schema->addField(new RepresentationFieldSchema('name', $users, 'name'));
		$schema->addField(new RepresentationFieldSchema('managerId', $users, 'id', sourcePath: ['manager']));
		$schema->addField(new RepresentationFieldSchema('managerName', $users, 'name', sourcePath: ['manager']));
		$object = (object) ['id' => 10, 'name' => 'User', 'managerId' => 20, 'managerName' => 'Manager'];
		$intents = new RepresentationIntentStore();
		if ($storedLifecycle !== null) {
			$intents->set($object, new RepresentationIntent($storedLifecycle));
		}

		$state = (new RepresentationAdoptionEngine())->attach(
			$object,
			new RepresentationAdoptionContext(
				schema: $schema,
				policy: $policy,
				intent: $contextLifecycle !== null ? new RepresentationIntent($contextLifecycle) : null,
			),
			new SessionContext(intents: $intents),
		);

		self::assertSame($expectedRoot, $state->requireRootRecord()->getLifecycle());
		self::assertSame($expectedRelated, $state->getRecordForField($state->getSchema()->getField('managerName'))->getLifecycle());
		self::assertSame('User', $state->requireRootRecord()->getValue('name'));
		self::assertSame('Manager', $state->getRecordForField($state->getSchema()->getField('managerName'))->getValue('name'));
	}

	/** @return iterable<string, array{AdoptionPolicy, ?RepresentationIntentLifecycle, ?RepresentationIntentLifecycle, RecordLifecycle, RecordLifecycle}> */
	public static function rootIntentPolicies(): iterable
	{
		yield 'stored update overrides create default' => [AdoptionPolicy::Create, RepresentationIntentLifecycle::Update, null, RecordLifecycle::DIRTY, RecordLifecycle::NEW];
		yield 'stored create overrides patch default' => [AdoptionPolicy::Patch, RepresentationIntentLifecycle::Create, null, RecordLifecycle::NEW, RecordLifecycle::DIRTY];
		yield 'stored update precedes supplied create' => [AdoptionPolicy::Create, RepresentationIntentLifecycle::Update, RepresentationIntentLifecycle::Create, RecordLifecycle::DIRTY, RecordLifecycle::NEW];
		yield 'supplied create overrides patch default' => [AdoptionPolicy::Patch, null, RepresentationIntentLifecycle::Create, RecordLifecycle::NEW, RecordLifecycle::DIRTY];
	}

	private function makeSelfRelationRegistry(): Registry
	{
		$registry = new Registry();

		$registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('manager_id', 'int')->end()
			->field('name', 'string')->end();

		$registry->getCollection('users')
			->belongsTo('manager', 'users')
			->innerKey('manager_id')
			->outerKey('id');

		return $registry;
	}
}
