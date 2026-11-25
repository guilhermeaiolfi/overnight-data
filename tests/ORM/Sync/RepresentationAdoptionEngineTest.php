<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\Definition\Registry;
use ON\Data\ORM\Record\RecordLifecycle;
use ON\Data\ORM\Representation\Sync\FlatIntentOp;
use ON\Data\ORM\Representation\Sync\RepresentationAdoptionEngine;
use ON\Data\ORM\Representation\Sync\RepresentationIntent;
use ON\Data\ORM\Representation\Sync\RepresentationIntentLifecycle;
use ON\Data\ORM\SessionContext;
use ON\Data\Schema\RepresentationFieldSchema;
use ON\Data\Schema\RepresentationSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RepresentationAdoptionEngineTest extends TestCase
{
	#[DataProvider('rootIntentPolicies')]
	public function testFlatSourceCanOverrideRootIntent(
		RepresentationIntentLifecycle $rootLifecycle,
		?RepresentationIntentLifecycle $flatLifecycle,
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
		$session = new SessionContext();
		$intent = new RepresentationIntent($rootLifecycle);
		$session->getIntents()->set($object, $intent);
		if ($flatLifecycle !== null) {
			$intent->addFlatOp(new FlatIntentOp('manager', $flatLifecycle));
		}
		$state = (new RepresentationAdoptionEngine())->attach($object, $schema, $session);

		self::assertNull($session->getIntents()->get($object));
		self::assertSame($expectedRoot, $state->requireRootRecord()->getLifecycle());
		self::assertSame($expectedRelated, $state->getRecordForField($state->getSchema()->getField('managerName'))->getLifecycle());
		self::assertSame('User', $state->requireRootRecord()->getValue('name'));
		self::assertSame('Manager', $state->getRecordForField($state->getSchema()->getField('managerName'))->getValue('name'));
	}

	/** @return iterable<string, array{RepresentationIntentLifecycle, ?RepresentationIntentLifecycle, RecordLifecycle, RecordLifecycle}> */
	public static function rootIntentPolicies(): iterable
	{
		yield 'flat defaults to root create' => [RepresentationIntentLifecycle::Create, null, RecordLifecycle::NEW, RecordLifecycle::NEW];
		yield 'flat defaults to root update' => [RepresentationIntentLifecycle::Update, null, RecordLifecycle::DIRTY, RecordLifecycle::DIRTY];
		yield 'flat create on root update' => [RepresentationIntentLifecycle::Update, RepresentationIntentLifecycle::Create, RecordLifecycle::DIRTY, RecordLifecycle::NEW];
		yield 'flat update on root create' => [RepresentationIntentLifecycle::Create, RepresentationIntentLifecycle::Update, RecordLifecycle::NEW, RecordLifecycle::DIRTY];
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
