<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\RepresentationRelationSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\Sync\RepresentationReader;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class RepresentationReaderTest extends TestCase
{
	use OrmFixture;

	public function testReadsFieldValues(): void
	{
		$row = new stdClass();
		$row->name = 'A1';

		self::assertSame(['name' => 'A1'], $this->reader()->read($row, $this->fieldSchema('name')));

		$dto = new PublicPropertyUser();
		$dto->name = 'A1';

		self::assertSame(['name' => 'A1'], $this->reader()->read($dto, $this->fieldSchema('name')));

		$row->name = null;

		self::assertSame(['name' => null], $this->reader()->read($row, $this->fieldSchema('name')));
	}

	public function testReadsNestedPath(): void
	{
		$row = new stdClass();
		$row->author = new stdClass();
		$row->author->name = 'Ada';

		self::assertSame(['author.name' => 'Ada'], $this->reader()->read($row, $this->fieldSchema('author.name')));

		$post = new PublicPropertyPost();
		$post->author = new PublicPropertyUser();
		$post->author->name = 'Ada';

		self::assertSame(['author.name' => 'Ada'], $this->reader()->read($post, $this->fieldSchema('author.name')));

		$row = new stdClass();
		$row->posts = [];
		$row->posts[0] = new stdClass();
		$row->posts[0]->title = 'Hello';

		self::assertSame(
			['posts.0.title' => 'Hello'],
			$this->reader()->read($row, $this->fieldSchema('posts.0.title'))
		);
	}

	public function testRejectsMissingPathSegment(): void
	{
		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('name');

		$this->reader()->read(new stdClass(), $this->fieldSchema('name'));
	}

	public function testRejectsMissingNestedPathSegment(): void
	{
		$row = new stdClass();
		$row->author = new stdClass();

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('author.name');

		$this->reader()->read($row, $this->fieldSchema('author.name'));
	}

	public function testBaselineValuesStartsFromSeedAndMergesNonKeyFields(): void
	{
		$key = $this->posts()->getKey(['id' => 123]);
		$representation = $this->representation(['id' => 123, 'title' => 'Draft']);
		$schema = $this->postSchemaWithId();

		$values = $this->reader()->baselineValues($representation, $schema, $key->getValues());

		self::assertSame(['id' => 123, 'title' => 'Draft'], $values);
	}

	public function testBaselineValuesIgnoresMissingNonKeyPaths(): void
	{
		$key = $this->posts()->getKey(['id' => 123]);
		$representation = $this->representation(['id' => 123]);
		$schema = $this->postSchemaWithId();

		$values = $this->reader()->baselineValues($representation, $schema, $key->getValues());

		self::assertSame(['id' => 123], $values);
	}

	public function testBaselineValuesDoesNotOverwriteSeedKeys(): void
	{
		$key = $this->posts()->getKey(['id' => 123]);
		$representation = $this->representation(['id' => 999, 'title' => 'Draft']);
		$schema = $this->postSchemaWithId();

		$values = $this->reader()->baselineValues($representation, $schema, $key->getValues());

		self::assertSame(['id' => 123, 'title' => 'Draft'], $values);
	}

	public function testRejectsIntermediateNonObjectPathSegment(): void
	{
		$row = new stdClass();
		$row->author = 'Ada';

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('author.name');

		$this->reader()->read($row, $this->fieldSchema('author.name'));
	}

	public function testReadsManyRelationItems(): void
	{
		$item = new stdClass();
		$representation = $this->representation(['posts' => [$item]]);

		self::assertSame([$item], $this->reader()->readItems($representation, $this->postsRelationSchema(), $this->syncError(...)));
	}

	public function testTreatsNullManyRelationAsEmptyList(): void
	{
		$representation = $this->representation(['posts' => null]);

		self::assertSame([], $this->reader()->readItems($representation, $this->postsRelationSchema(), $this->syncError(...)));
	}

	public function testRejectsNonIterableManyRelationValue(): void
	{
		$representation = $this->representation(['posts' => 'bad']);

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('posts');
		$this->expectExceptionMessage('iterable');

		$this->reader()->readItems($representation, $this->postsRelationSchema(), $this->syncError(...));
	}

	public function testRejectsNonObjectManyRelationItem(): void
	{
		$representation = $this->representation(['posts' => ['bad']]);

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('posts');
		$this->expectExceptionMessage('only contain objects');

		$this->reader()->readItems($representation, $this->postsRelationSchema(), $this->syncError(...));
	}

	public function testReadsSingleRelationTarget(): void
	{
		$target = new stdClass();
		$representation = $this->representation(['profile' => $target]);

		self::assertSame($target, $this->reader()->readTarget($representation, $this->profileRelationSchema(), $this->syncError(...)));
	}

	public function testTreatsNullSingleRelationAsNull(): void
	{
		$representation = $this->representation(['profile' => null]);

		self::assertNull($this->reader()->readTarget($representation, $this->profileRelationSchema(), $this->syncError(...)));
	}

	public function testRejectsInvalidSingleRelationTarget(): void
	{
		$schema = $this->profileRelationSchema();

		foreach ([
			['profile' => 123],
			['profile' => []],
			['profile' => ['bad']],
		] as $values) {
			try {
				$this->reader()->readTarget($this->representation($values), $schema, $this->syncError(...));
				self::fail('Expected SyncException for invalid single relation target.');
			} catch (SyncException $exception) {
				self::assertStringContainsString('profile', $exception->getMessage());
				self::assertStringContainsString('object value or null', $exception->getMessage());
			}
		}
	}

	public function testReadsWritableAndReadOnlySchemas(): void
	{
		$row = new stdClass();
		$row->name = 'Ada';
		$row->upperName = 'ADA';
		$schema = new RepresentationSchema($this->users());
		$schema->addField(new RepresentationFieldSchema('name', $this->users(), 'name'));
		$schema->addField(new RepresentationFieldSchema('upperName', $this->users(), 'name', false));

		self::assertSame(
			['name' => 'Ada', 'upperName' => 'ADA'],
			$this->reader()->read($row, $schema)
		);
	}

	public function testPreservesSchemaInsertionOrder(): void
	{
		$row = new stdClass();
		$row->email = 'ada@example.test';
		$row->name = 'Ada';
		$schema = new RepresentationSchema($this->users());
		$schema->addField(new RepresentationFieldSchema('email', $this->users(), 'email'));
		$schema->addField(new RepresentationFieldSchema('name', $this->users(), 'name'));

		self::assertSame(
			['email' => 'ada@example.test', 'name' => 'Ada'],
			$this->reader()->read($row, $schema)
		);
	}

	public function testDoesNotMutateRepresentation(): void
	{
		$row = new stdClass();
		$row->name = 'Ada';
		$before = clone $row;

		$this->reader()->read($row, $this->fieldSchema('name'));

		self::assertEquals($before, $row);
	}

	public function testCallerCanTransformRelationErrorForGraphAdoption(): void
	{
		$representation = $this->representation(['posts' => 'bad']);

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('posts');
		$this->expectExceptionMessage('iterable');
		$this->expectExceptionMessage('during graph adoption');

		$this->reader()->readItems($representation, $this->postsRelationSchema(), $this->adoptionError(...));
	}

	private function reader(): RepresentationReader
	{
		return new RepresentationReader();
	}

	/**
	 * @param non-empty-string $message
	 */
	private function syncError(string $message): SyncException
	{
		return new SyncException($message);
	}

	/**
	 * @param non-empty-string $message
	 */
	private function adoptionError(string $message): StateException
	{
		return new StateException(rtrim($message, '.') . ' during graph adoption.');
	}

	private function fieldSchema(string $path): RepresentationSchema
	{
		$schema = new RepresentationSchema($this->users());
		$schema->addField(new RepresentationFieldSchema($path, $this->users(), 'name'));

		return $schema;
	}

	private function postsRelationSchema(): RepresentationRelationSchema
	{
		return new RepresentationRelationSchema(
			'posts',
			$this->users(),
			'posts',
			$this->postSchema()
		);
	}

	private function profileRelationSchema(): RepresentationRelationSchema
	{
		return new RepresentationRelationSchema(
			'profile',
			$this->users(),
			'profile',
			$this->profileSchema()
		);
	}
}

final class PublicPropertyUser
{
	public ?string $name = null;
}

final class PublicPropertyPost
{
	public PublicPropertyUser $author;
}
