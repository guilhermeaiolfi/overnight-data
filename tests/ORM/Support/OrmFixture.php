<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Support;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Relation\ToManyRelationStore;
use ON\Data\ORM\Relation\ToOneRelationStore;
use ON\Data\ORM\SessionContext;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStore;
use stdClass;

trait OrmFixture
{
	/**
	 * @param array<string, mixed> $values
	 */
	protected function representation(array $values): stdClass
	{
		$representation = new stdClass();
		foreach ($values as $path => $value) {
			$representation->{$path} = $value;
		}

		return $representation;
	}

	protected function representations(RepresentationState ...$states): RepresentationStore
	{
		$store = new RepresentationStore();
		foreach ($states as $state) {
			RepresentationStateObjectRegistry::addTo($store, $state);
		}

		return $store;
	}

	protected function records(RecordState ...$records): RecordStateStore
	{
		$store = new RecordStateStore();
		foreach ($records as $record) {
			$store->add($record);
		}

		return $store;
	}

	protected function context(
		?RepresentationStore $representations = null,
		?RecordStateStore $records = null,
		?ToManyRelationStore $relations = null,
		?ToOneRelationStore $references = null,
	): SessionContext {
		return new SessionContext(
			$records,
			$representations,
			$relations,
			$references
		);
	}

	protected function users(): CollectionInterface
	{
		return (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
	}

	protected function posts(): CollectionInterface
	{
		return (new Registry())
			->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end();
	}

	protected function profiles(): CollectionInterface
	{
		return (new Registry())
			->collection('profiles')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('label', 'string')->end();
	}

	protected function comments(): CollectionInterface
	{
		return (new Registry())
			->collection('comments')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('body', 'string')->end();
	}

	protected function userBinding(): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('name', RecordFieldRef::template($this->users(), 'name')));

		return $binding;
	}

	protected function userBindingWithId(): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('id', RecordFieldRef::template($this->users(), 'id')));
		$binding->addField(new RepresentationFieldBinding('name', RecordFieldRef::template($this->users(), 'name')));

		return $binding;
	}

	protected function postBinding(): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('title', RecordFieldRef::template($this->posts(), 'title')));

		return $binding;
	}

	protected function postBindingWithId(): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('id', RecordFieldRef::template($this->posts(), 'id')));
		$binding->addField(new RepresentationFieldBinding('title', RecordFieldRef::template($this->posts(), 'title')));

		return $binding;
	}

	protected function profileBinding(): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('label', RecordFieldRef::template($this->profiles(), 'label')));

		return $binding;
	}

	protected function commentBinding(): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('body', RecordFieldRef::template($this->comments(), 'body')));

		return $binding;
	}

	protected function userBindingFor(RecordState $record): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('name', RecordFieldRef::forState($record, 'name')));

		return $binding;
	}

	protected function postBindingFor(RecordState $record): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->addField(new RepresentationFieldBinding('title', RecordFieldRef::forState($record, 'title')));

		return $binding;
	}

	/**
	 * @param array<string, int> $baselineRevisions
	 */
	protected function tracked(
		object $representation,
		RepresentationBinding $binding,
		array $baselineRevisions = [],
	): RepresentationState {
		return RepresentationStateObjectRegistry::remember(
			$representation,
			new RepresentationState($binding, $baselineRevisions)
		);
	}

	/**
	 * @return array<string, int>
	 */
	protected function baselineRevisions(RepresentationBinding $binding): array
	{
		$baselineRevisions = [];
		foreach ($binding->getFields() as $fieldBinding) {
			$baselineRevisions[$fieldBinding->getField()->getRecordHash()] = 1;
		}

		return $baselineRevisions;
	}
}
