<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Support;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\SessionContext;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationSchema;
use ON\Data\ORM\State\RepresentationFieldSchema;
use ON\Data\ORM\State\RepresentationFieldStateItem;
use ON\Data\ORM\State\RepresentationRelationStateItem;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStateStore;
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

	protected function representations(RepresentationState ...$states): RepresentationStateStore
	{
		$store = new RepresentationStateStore();
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
		?RepresentationStateStore $representations = null,
		?RecordStateStore $records = null,
		?RelationStateStore $toManyRelations = null,
		?RelationStateStore $toOneRelations = null,
	): SessionContext {
		return new SessionContext(
			$records,
			$representations,
			$toManyRelations,
			$toOneRelations
		);
	}

	protected function users(): CollectionInterface
	{
		$registry = new Registry();
		$users = $registry
			->collection('users')
			->primaryKey('id')
			->field('tenant_id', 'int')->end()
			->field('id', 'int')->end()
			->field('name', 'string')->end()
			->field('email', 'string')->end()
			->field('user_id', 'int')->end()
			->field('manager_id', 'int')->end();
		$users->hasMany('posts', 'posts');
		$users->hasOne('profile', 'profiles');

		return $users;
	}

	protected function posts(): CollectionInterface
	{
		$registry = new Registry();
		$posts = $registry
			->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end()
			->field('body', 'string')->end()
			->field('user_id', 'int')->end();
		$posts->belongsTo('author', 'users');
		$posts->hasMany('comments', 'comments');
		$posts->hasMany('tags', 'tags');

		return $posts;
	}

	protected function profiles(): CollectionInterface
	{
		$registry = new Registry();
		$profiles = $registry
			->collection('profiles')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('label', 'string')->end();
		$profiles->belongsTo('user', 'users');

		return $profiles;
	}

	protected function comments(): CollectionInterface
	{
		return (new Registry())
			->collection('comments')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('body', 'string')->end();
	}

	protected function userBinding(): RepresentationSchema
	{
		$users = $this->users();
		$binding = new RepresentationSchema($users);
		$binding->addField(new RepresentationFieldSchema('name', $users, 'name'));

		return $binding;
	}

	protected function userBindingWithId(): RepresentationSchema
	{
		$users = $this->users();
		$binding = new RepresentationSchema($users);
		$binding->addField(new RepresentationFieldSchema('id', $users, 'id'));
		$binding->addField(new RepresentationFieldSchema('name', $users, 'name'));

		return $binding;
	}

	protected function postBinding(): RepresentationSchema
	{
		$posts = $this->posts();
		$binding = new RepresentationSchema($posts);
		$binding->addField(new RepresentationFieldSchema('title', $posts, 'title'));

		return $binding;
	}

	protected function postBindingWithId(): RepresentationSchema
	{
		$posts = $this->posts();
		$binding = new RepresentationSchema($posts);
		$binding->addField(new RepresentationFieldSchema('id', $posts, 'id'));
		$binding->addField(new RepresentationFieldSchema('title', $posts, 'title'));

		return $binding;
	}

	protected function profileBinding(): RepresentationSchema
	{
		$profiles = $this->profiles();
		$binding = new RepresentationSchema($profiles);
		$binding->addField(new RepresentationFieldSchema('label', $profiles, 'label'));

		return $binding;
	}

	protected function commentBinding(): RepresentationSchema
	{
		$comments = $this->comments();
		$binding = new RepresentationSchema($comments);
		$binding->addField(new RepresentationFieldSchema('body', $comments, 'body'));

		return $binding;
	}

	protected function userBindingFor(RecordState $record): RepresentationSchema
	{
		$binding = new RepresentationSchema($record->getCollection());
		$binding->addField(new RepresentationFieldSchema('name', $record->getCollection(), 'name'));

		return $binding;
	}

	protected function postBindingFor(RecordState $record): RepresentationSchema
	{
		$binding = new RepresentationSchema($record->getCollection());
		$binding->addField(new RepresentationFieldSchema('title', $record->getCollection(), 'title'));

		return $binding;
	}

	/**
	 * @param list<RecordState> $records
	 */
	protected function tracked(
		object $representation,
		RepresentationSchema $binding,
		array $records = [],
	): RepresentationState {
		return RepresentationStateObjectRegistry::remember(
			$representation,
			new RepresentationState($binding, $this->fieldItemsFor($binding, $records), $this->relationItemsFor($binding, $records))
		);
	}

	/**
	 * @param list<RecordState> $records
	 * @return list<RepresentationFieldStateItem>
	 */
	protected function fieldItemsFor(RepresentationSchema $binding, array $records): array
	{
		$items = [];
		foreach ($binding->getFields() as $fieldSchema) {
			foreach ($records as $record) {
				if ($record->getCollection()->getName() !== $fieldSchema->getCollectionName()) {
					continue;
				}

				$items[] = new RepresentationFieldStateItem(
					$fieldSchema,
					$record,
					$fieldSchema->getFieldName(),
					$record->getRevision()
				);

				break;
			}
		}

		return $items;
	}

	/**
	 * @param list<RecordState> $records
	 * @return list<RepresentationRelationStateItem>
	 */
	protected function relationItemsFor(RepresentationSchema $binding, array $records): array
	{
		$items = [];
		foreach ($binding->getRelations() as $relationSchema) {
			foreach ($records as $record) {
				if ($record->getCollection()->getName() !== $relationSchema->getOwnerCollectionName()) {
					continue;
				}

				$items[] = new RepresentationRelationStateItem(
					$relationSchema,
					$record,
					$relationSchema->getRelationName()
				);

				break;
			}
		}

		return $items;
	}
}
