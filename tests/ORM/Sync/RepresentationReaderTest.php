<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Representation\Sync\PathReadStatus;
use ON\Data\ORM\Representation\Sync\RepresentationReader;
use ON\Data\Schema\RepresentationRelationSchema;
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

		self::assertSame('A1', $this->reader()->readPath($row, 'name')->requireValue());

		$dto = new PublicPropertyUser();
		$dto->name = 'A1';

		self::assertSame('A1', $this->reader()->readPath($dto, 'name')->requireValue());

		$row->name = null;

		self::assertNull($this->reader()->readPath($row, 'name')->requireValue());
	}

	public function testReadsNestedPath(): void
	{
		$row = new stdClass();
		$row->author = new stdClass();
		$row->author->name = 'Ada';

		self::assertSame('Ada', $this->reader()->readPath($row, 'author.name')->requireValue());

		$post = new PublicPropertyPost();
		$post->author = new PublicPropertyUser();
		$post->author->name = 'Ada';

		self::assertSame('Ada', $this->reader()->readPath($post, 'author.name')->requireValue());

		$row = new stdClass();
		$row->posts = [];
		$row->posts[0] = new stdClass();
		$row->posts[0]->title = 'Hello';

		self::assertSame(
			'Hello',
			$this->reader()->readPath($row, 'posts.0.title')->requireValue()
		);
	}

	public function testReadPathDistinguishesPresentNullMissingAndInvalidPaths(): void
	{
		$row = new stdClass();
		$row->author = new stdClass();
		$row->author->name = null;

		$present = $this->reader()->readPath($row, 'author.name');
		self::assertSame(PathReadStatus::Present, $present->getStatus());
		self::assertTrue($present->isPresent());
		self::assertNull($present->requireValue());

		$missing = $this->reader()->readPath($row, 'author.email');
		self::assertSame(PathReadStatus::Missing, $missing->getStatus());
		self::assertTrue($missing->isMissing());

		$row->author = 'Ada';
		$invalid = $this->reader()->readPath($row, 'author.name');
		self::assertSame(PathReadStatus::Invalid, $invalid->getStatus());
		self::assertTrue($invalid->isInvalid());
	}

	public function testReadPathPreservesLiteralDottedPropertyPrecedenceAndArrayPathRules(): void
	{
		$row = new stdClass();
		$row->{'author.name'} = 'Literal';
		$row->author = ['name' => 'Nested'];

		self::assertSame('Literal', $this->reader()->readPath($row, 'author.name')->requireValue());

		$row = new stdClass();
		$row->posts = [new stdClass()];
		$row->posts[0]->title = 'Hello';
		self::assertSame('Hello', $this->reader()->readPath($row, 'posts.0.title')->requireValue());
		self::assertTrue($this->reader()->readPath($row, 'posts.first.title')->isInvalid());
		self::assertTrue($this->reader()->readPath($row, 'posts.1.title')->isMissing());
	}

	public function testUnsuccessfulReadPathRetainsStrictReadError(): void
	{
		$result = $this->reader()->readPath(new stdClass(), 'name');

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage("segment 'name' is missing");

		$result->requireValue();
	}

	public function testRejectsMissingPathSegment(): void
	{
		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('name');

		$this->reader()->readPath(new stdClass(), 'name')->requireValue();
	}

	public function testRejectsMissingNestedPathSegment(): void
	{
		$row = new stdClass();
		$row->author = new stdClass();

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('author.name');

		$this->reader()->readPath($row, 'author.name')->requireValue();
	}

	public function testRejectsIntermediateNonObjectPathSegment(): void
	{
		$row = new stdClass();
		$row->author = 'Ada';

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('author.name');

		$this->reader()->readPath($row, 'author.name')->requireValue();
	}

	public function testReadsManyRelationItems(): void
	{
		$item = new stdClass();
		$representation = $this->representation(['posts' => [$item]]);

		self::assertSame([$item], $this->reader()->readItems($representation, $this->postsRelationSchema(), $this->syncError(...))->requireValue());
	}

	public function testTreatsNullManyRelationAsEmptyList(): void
	{
		$representation = $this->representation(['posts' => null]);

		self::assertSame([], $this->reader()->readItems($representation, $this->postsRelationSchema(), $this->syncError(...))->requireValue());
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

		self::assertSame($target, $this->reader()->readTarget($representation, $this->profileRelationSchema(), $this->syncError(...))->requireValue());
	}

	public function testRelationTargetPreservesLiveObjectIdentityOnDto(): void
	{
		$target = new PublicPropertyUser();
		$target->name = 'Ada';
		$dto = new PublicPropertyUserWithProfile();
		$dto->profile = $target;

		self::assertSame(
			$target,
			$this->reader()->readTarget($dto, $this->profileRelationSchema(), $this->syncError(...))->requireValue()
		);
	}

	public function testTreatsNullSingleRelationAsNull(): void
	{
		$representation = $this->representation(['profile' => null]);

		self::assertNull($this->reader()->readTarget($representation, $this->profileRelationSchema(), $this->syncError(...))->requireValue());
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

	public function testDoesNotMutateRepresentation(): void
	{
		$row = new stdClass();
		$row->name = 'Ada';
		$before = clone $row;

		$this->reader()->readPath($row, 'name')->requireValue();

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

final class PublicPropertyUserWithProfile
{
	public ?string $name = null;
	public ?PublicPropertyUser $profile = null;
}

final class PublicPropertyPost
{
	public PublicPropertyUser $author;
}
