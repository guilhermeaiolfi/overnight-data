<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Support;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Session;
use ON\Data\ORM\SessionContext;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\State\RepresentationFieldStateItem;
use ON\Data\ORM\Representation\State\RepresentationRelationStateItem;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\State\RepresentationStateStore;
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
		$relations = $toManyRelations ?? $toOneRelations ?? new RelationStateStore();
		if ($toOneRelations !== null && $toOneRelations !== $relations) {
			foreach ($toOneRelations->getAll() as $relation) {
				$relations->add($relation);
			}
		}

		return new SessionContext(
			$records,
			$representations,
			$relations
		);
	}

	protected function adoptRecord(
		Session $session,
		object $representation,
		RepresentationSchema $schema,
		RecordState $record,
	): RepresentationState {
		return $session->adoptRecord($representation, $schema, $record);
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

	protected function userSchema(): RepresentationSchema
	{
		$users = $this->users();
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('name', $users, 'name'));

		return $schema;
	}

	protected function userSchemaWithId(): RepresentationSchema
	{
		$users = $this->users();
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('id', $users, 'id'));
		$schema->addField(new RepresentationFieldSchema('name', $users, 'name'));

		return $schema;
	}

	protected function postSchema(): RepresentationSchema
	{
		$posts = $this->posts();
		$schema = new RepresentationSchema($posts);
		$schema->addField(new RepresentationFieldSchema('title', $posts, 'title'));

		return $schema;
	}

	protected function postSchemaWithId(): RepresentationSchema
	{
		$posts = $this->posts();
		$schema = new RepresentationSchema($posts);
		$schema->addField(new RepresentationFieldSchema('id', $posts, 'id'));
		$schema->addField(new RepresentationFieldSchema('title', $posts, 'title'));

		return $schema;
	}

	protected function profileSchema(): RepresentationSchema
	{
		$profiles = $this->profiles();
		$schema = new RepresentationSchema($profiles);
		$schema->addField(new RepresentationFieldSchema('label', $profiles, 'label'));

		return $schema;
	}

	protected function commentSchema(): RepresentationSchema
	{
		$comments = $this->comments();
		$schema = new RepresentationSchema($comments);
		$schema->addField(new RepresentationFieldSchema('body', $comments, 'body'));

		return $schema;
	}

	protected function userSchemaFor(RecordState $record): RepresentationSchema
	{
		$schema = new RepresentationSchema($record->getCollection());
		$schema->addField(new RepresentationFieldSchema('name', $record->getCollection(), 'name'));

		return $schema;
	}

	protected function postSchemaFor(RecordState $record): RepresentationSchema
	{
		$schema = new RepresentationSchema($record->getCollection());
		$schema->addField(new RepresentationFieldSchema('title', $record->getCollection(), 'title'));

		return $schema;
	}

	/**
	 * @param list<RecordState> $records
	 */
	protected function tracked(
		object $representation,
		RepresentationSchema $schema,
		array $records = [],
	): RepresentationState {
		return RepresentationStateObjectRegistry::remember(
			$representation,
			new RepresentationState($schema, $this->fieldItemsFor($schema, $records), $this->relationItemsFor($schema, $records))
		);
	}

	/**
	 * @param list<RecordState> $records
	 * @return list<RepresentationFieldStateItem>
	 */
	protected function fieldItemsFor(RepresentationSchema $schema, array $records): array
	{
		$items = [];
		foreach ($schema->getFields() as $fieldSchema) {
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
	protected function relationItemsFor(RepresentationSchema $schema, array $records): array
	{
		$items = [];
		foreach ($schema->getRelations() as $relationSchema) {
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
