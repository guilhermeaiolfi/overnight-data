<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Foundation;

use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\Schema\RepresentationFieldSchema;
use ON\Data\Schema\RepresentationRelationSchema;
use ON\Data\Schema\RepresentationSchema;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class RepresentationStateTest extends TestCase
{
	use OrmFixture;

	public function testRepresentationCanMapToMultipleRecordStates(): void
	{
		$users = $this->users();
		$posts = $this->posts();
		$userRecord = RecordState::clean($users->getKey(1), ['id' => 1, 'name' => 'Ada']);
		$postRecord = RecordState::clean($posts->getKey(10), ['id' => 10, 'title' => 'Hello']);
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('userName', $users, 'name'));
		$schema->addField(new RepresentationFieldSchema('postTitle', $posts, 'title', sourcePath: ['posts']));

		$state = RepresentationState::fromRecords($schema, [
			RepresentationFieldSchema::sourcePathKey([]) => $userRecord,
			RepresentationFieldSchema::sourcePathKey(['posts']) => $postRecord,
		]);

		self::assertSame($userRecord, $state->getRecordForField($state->getSchema()->getField('userName')));
		self::assertSame($postRecord, $state->getRecordForField($state->getSchema()->getField('postTitle')));
		self::assertSame([$userRecord], $state->getRecordsForCollection($users));
		self::assertSame([$postRecord], $state->getRecordsForCollection($posts));
	}

	public function testRepresentationStateStoresBaselinesAndWritableFields(): void
	{
		$users = $this->users();
		$record = RecordState::clean($users->getKey(1), ['id' => 1, 'name' => 'Ada', 'email' => 'ada@example.test']);
		$record->setValue('name', 'Ada Lovelace');
		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('displayName', $users, 'name'));
		$schema->addField(new RepresentationFieldSchema('email', $users, 'email', writable: false));

		$state = RepresentationState::fromRecords($schema, [
			RepresentationFieldSchema::sourcePathKey([]) => $record,
		]);
		$nameField = $schema->getField('displayName');
		$emailField = $schema->getField('email');

		self::assertSame($record, $state->getRecordForField($nameField));
		self::assertSame('name', $nameField->getFieldName());
		self::assertSame('Ada Lovelace', $state->getBaseline('displayName'));
		self::assertSame([$nameField], $state->getWritableFields());
		self::assertTrue($emailField->isReadOnly());
	}

	public function testRepresentationSchemaMayBeReusedAsChildOrRelationItemTemplate(): void
	{
		$users = $this->users();
		$posts = $this->posts();
		$postSchema = new RepresentationSchema($posts);
		$postTitle = new RepresentationFieldSchema('title', $posts, 'title');
		$postSchema->addField($postTitle);
		$userSchema = new RepresentationSchema($users);
		$relationSchema = new RepresentationRelationSchema('posts', $users, 'posts', $postSchema);
		$userSchema->addRelation($relationSchema);

		self::assertSame($postSchema, $relationSchema->getRelatedSchema());
		self::assertSame($posts, $relationSchema->getRelatedSchema()->getCollection());
		self::assertSame([$postTitle], $relationSchema->getRelatedSchema()->getFields());
	}

	public function testRepresentationStateIsConcreteAndNotATemplate(): void
	{
		$users = $this->users();
		$posts = $this->posts();
		$userRecord = RecordState::clean($users->getKey(1), ['id' => 1, 'name' => 'Ada']);
		$relatedSchema = new RepresentationSchema($posts);
		$rootSchema = new RepresentationSchema($users);
		$rootSchema->addField(new RepresentationFieldSchema('name', $users, 'name'));
		$rootSchema->addRelation(new RepresentationRelationSchema('posts', $users, 'posts', $relatedSchema));

		$state = RepresentationState::fromRecords($rootSchema, [
			RepresentationFieldSchema::sourcePathKey([]) => $userRecord,
		]);
		$relation = $state->getSchema()->getRelation('posts');
		$constructor = (new ReflectionClass(RepresentationRelationSchema::class))->getConstructor();

		self::assertSame($userRecord, $state->getRootRecord());
		self::assertSame($userRecord, $state->getOwnerRecord('posts'));
		self::assertSame($relatedSchema, $relation->getRelatedSchema());
		self::assertNotNull($constructor);
		self::assertSame('relatedSchema', $constructor->getParameters()[3]->getName());
		self::assertSame(RepresentationSchema::class, (string) $constructor->getParameters()[3]->getType());
	}

	public function testRepresentationStatesKeepDistinctRecordsForNewChildren(): void
	{
		$posts = $this->posts();
		$schema = new RepresentationSchema($posts);
		$schema->addField(new RepresentationFieldSchema('title', $posts, 'title'));
		$firstRecord = RecordState::new($posts, ['title' => 'First']);
		$secondRecord = RecordState::new($posts, ['title' => 'Second']);

		$firstState = RepresentationState::fromRecords($schema, [
			RepresentationFieldSchema::sourcePathKey([]) => $firstRecord,
		]);
		$secondState = RepresentationState::fromRecords($schema, [
			RepresentationFieldSchema::sourcePathKey([]) => $secondRecord,
		]);

		self::assertTrue($firstState->hasBaseline('title'));
		self::assertTrue($secondState->hasBaseline('title'));
		self::assertSame($firstRecord, $firstState->getRecordForField($firstState->getSchema()->getField('title')));
		self::assertSame($secondRecord, $secondState->getRecordForField($secondState->getSchema()->getField('title')));
		self::assertNotSame(
			$firstState->getRecordForField($firstState->getSchema()->getField('title')),
			$secondState->getRecordForField($secondState->getSchema()->getField('title')),
		);
	}
}
