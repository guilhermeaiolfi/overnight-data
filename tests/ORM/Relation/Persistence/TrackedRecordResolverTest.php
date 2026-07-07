<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Relation\Persistence;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\HasManyRelation;
use ON\Data\ORM\Exception\RelationPersistenceException;
use ON\Data\ORM\Persistence\CommandBuffer;
use ON\Data\ORM\Persistence\PersistenceContext;
use ON\Data\ORM\Relation\Persistence\TrackedRecordResolver;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStore;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\ORM\Support\OrmFixture;
use Tests\ON\Data\ORM\Support\RepresentationStateObjectRegistry;

final class TrackedRecordResolverTest extends TestCase
{
	use OrmFixture;

	public function testResolvesTrackedRepresentationToRecordState(): void
	{
		[$relation, $users, $posts] = $this->relation();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$child = RecordState::clean($posts->getKey(5), ['id' => 5]);
		$item = new stdClass();
		$representations = $this->representations($this->tracked($item, $child));
		$records = $this->records($owner, $child);
		$context = new PersistenceContext($this->context($representations, $records), new CommandBuffer());

		$resolved = (new TrackedRecordResolver())->resolve($context, $relation, $item, 'child');

		self::assertSame($child, $resolved);
	}

	public function testThrowsWhenRepresentationIsNotTracked(): void
	{
		[$relation, $users, $posts] = $this->relation();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$child = RecordState::clean($posts->getKey(5), ['id' => 5]);
		$context = new PersistenceContext(
			$this->context(new RepresentationStore(), $this->records($owner, $child)),
			new CommandBuffer(),
		);

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage("Relation 'posts' child item is not tracked.");

		(new TrackedRecordResolver())->resolve($context, $relation, new stdClass(), 'child');
	}

	public function testThrowsWhenTrackedRepresentationHasNoRecordState(): void
	{
		[$relation, $users, $posts] = $this->relation();
		$owner = RecordState::clean($users->getKey(10), ['id' => 10]);
		$child = RecordState::clean($posts->getKey(5), ['id' => 5]);
		$item = new stdClass();
		$binding = new RepresentationBinding($posts);
		$binding->addField(new RepresentationFieldBinding('id', $posts, 'id'));
		$tracked = RepresentationStateObjectRegistry::remember($item, new RepresentationState($binding, []));
		$context = new PersistenceContext(
			$this->context($this->representations($tracked), $this->records($owner, $child)),
			new CommandBuffer(),
		);

		$this->expectException(RelationPersistenceException::class);
		$this->expectExceptionMessage("Relation 'posts' tracked child item cannot be resolved to a record state.");

		(new TrackedRecordResolver())->resolve($context, $relation, $item, 'child');
	}

	/**
	 * @return array{0: HasManyRelation, 1: CollectionInterface, 2: CollectionInterface}
	 */
	private function relation(): array
	{
		$registry = new Registry();
		$posts = $registry->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('user_id', 'int')->end();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end();

		return [$users->hasMany('posts', 'posts')->innerKey('id')->outerKey('user_id'), $users, $posts];
	}

	private function tracked(object $representation, RecordState $record): RepresentationState
	{
		return RepresentationStateObjectRegistry::remember(
			$representation,
			new RepresentationState($binding = $this->bindingFor($record), $this->fieldItemsFor($binding, [$record]))
		);
	}

	private function bindingFor(RecordState $record): RepresentationBinding
	{
		$binding = new RepresentationBinding($record->getCollection());
		foreach (array_keys($record->getValues()) as $field) {
			$field = (string) $field;
			$binding->addField(new RepresentationFieldBinding($field, $record->getCollection(), $field));
		}

		return $binding;
	}
}
