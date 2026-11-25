<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ArrayIterator;
use DateTimeImmutable;
use ON\Data\Mapper\Attribute\Hidden;
use ON\Data\Mapper\Attribute\MapTo;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Mapper\MapperInterface;
use ON\Data\Mapper\Mapping;
use ON\Data\Mapper\MappingBranch;
use ON\Data\Mapper\MappingOptions;
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

	public function testMapperHidesPropertiesAndResolvesRenamedNestedPaths(): void
	{
		$author = new class () extends stdClass {
			#[MapTo('name')]
			public $displayName = 'Ada';

			#[Hidden]
			public string $email = 'ada@example.test';
		};
		$post = new stdClass();
		$post->author = $author;

		$read = $this->reader()->readPass($post);

		self::assertSame($author, $read('author')->requireValue());
		self::assertSame('Ada', $read('author.name')->requireValue());
		self::assertTrue($read('author.displayName')->isMissing());
		self::assertTrue($read('author.email')->isMissing());
	}

	public function testDynamicPropertiesAreReadFromEachInstance(): void
	{
		$source = new class () extends stdClass {};
		$reader = $this->reader();
		self::assertTrue($reader->readPath($source, 'name')->isMissing());

		$other = clone $source;
		$other->name = 'Ada';

		self::assertSame('Ada', $reader->readPath($other, 'name')->requireValue());
		self::assertTrue($reader->readPath($source, 'name')->isMissing());

		$third = new class () extends stdClass {
			public string $title = 'Developer';
		};
		$fourth = clone $third;
		$third->name = 'Grace';

		self::assertSame('Grace', $reader->readPath($third, 'name')->requireValue());
		self::assertTrue($reader->readPath($fourth, 'name')->isMissing());
	}

	public function testReadPassMapsEachObjectOnceAndUsesTheConfiguredGateway(): void
	{
		$default = Mapping::getDefaultGateway();
		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->prepend(ReaderBagMapper::class);
		$reader = new RepresentationReader($gateway);
		$author = (object) ['displayName' => 'Ada'];
		$author->self = $author;
		$author->profile = new stdClass();
		$post = (object) ['author' => $author, 'editor' => $author];

		$read = $reader->readPass($post);

		self::assertSame('Ada', $read('author.name')->requireValue());
		self::assertSame('Ada', $read('editor.self.name')->requireValue());
		self::assertSame($author, $read('author.self')->requireValue());
		$mapper = $gateway->getMapperManager()->getMapper(ReaderBagMapper::class);
		self::assertInstanceOf(ReaderBagMapper::class, $mapper);
		self::assertSame(1, $mapper->reads);
		self::assertTrue($reader->readTarget($author, $this->profileRelationSchema(), $this->syncError(...))->isMissing());
		self::assertSame(2, $mapper->reads);

		$author->displayName = 'Grace';
		self::assertSame('Grace', $reader->readPath($post, 'author.name')->requireValue());
		self::assertSame(3, $mapper->reads);
		self::assertSame($default, Mapping::getDefaultGateway());
		self::assertTrue($this->reader()->readPath($post, 'author.name')->isMissing());
	}

	public function testReaderWithoutGatewayUsesTheCurrentDefault(): void
	{
		$reader = $this->reader();
		$default = Mapping::getDefaultGateway();
		$source = (object) ['displayName' => 'Ada', 'self' => null];
		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->prepend(ReaderBagMapper::class);

		Mapping::setDefaultGateway($gateway);

		try {
			self::assertSame('Ada', $reader->readPath($source, 'name')->requireValue());
		} finally {
			Mapping::setDefaultGateway($default);
		}

		self::assertTrue($reader->readPath($source, 'name')->isMissing());
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
		$representation = $this->representation(['posts' => new ArrayIterator([$item])]);

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

	public function testHiddenRelationsAreMissingJustLikeHiddenFields(): void
	{
		$representation = new class () {
			#[Hidden]
			public string $name = 'Owner';

			#[Hidden]
			public stdClass $profile;

			#[Hidden]
			public array $posts;
		};
		$representation->profile = new stdClass();
		$representation->posts = [new stdClass()];

		$reader = $this->reader();
		self::assertTrue($reader->readPath($representation, 'name')->isMissing());
		self::assertTrue($reader->readTarget($representation, $this->profileRelationSchema(), $this->syncError(...))->isMissing());
		self::assertTrue($reader->readItems($representation, $this->postsRelationSchema(), $this->syncError(...))->isMissing());
	}

	public function testMapperResolvesRenamedRelationPathSegments(): void
	{
		$container = new class (new stdClass()) {
			public function __construct(#[MapTo('profile')] public stdClass $details)
			{
			}
		};
		$representation = $this->representation(['relations' => $container]);
		$schema = new RepresentationRelationSchema('relations.profile', $this->users(), 'profile', $this->profileSchema());

		self::assertSame($container->details, $this->reader()->readTarget($representation, $schema, $this->syncError(...))->requireValue());
	}

	public function testScalarAndRelationReadsKeepLiveValuesInACyclicGraph(): void
	{
		$dto = new class () {
			public string $name = 'Owner';
			public stdClass $profile;
			/** @var list<PublicPropertyPost|stdClass> */
			public array $posts;
			public DateTimeImmutable $date;
			public ExampleStatus $status = ExampleStatus::Ready;
		};
		$profile = (object) ['owner' => $dto];
		$profile->self = $profile;
		$dto->profile = $profile;
		$dto->posts = [$profile, new PublicPropertyPost()];
		$dto->date = new DateTimeImmutable();

		$reader = $this->reader();
		$read = $reader->readPass($dto);
		self::assertSame('Owner', $read('profile.self.owner.name')->requireValue());
		self::assertSame($dto->posts, $read('posts')->requireValue());
		self::assertSame($dto->date, $read('date')->requireValue());
		self::assertSame(ExampleStatus::Ready, $read('status')->requireValue());
		self::assertSame($profile, $reader->readTarget($dto, $this->profileRelationSchema(), $this->syncError(...))->requireValue());
		self::assertSame($dto->posts, $reader->readItems($dto, $this->postsRelationSchema(), $this->syncError(...))->requireValue());
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

enum ExampleStatus: string
{
	case Ready = 'ready';
}

final class ReaderBagMapper implements MapperInterface
{
	public int $reads = 0;

	public static function canMap(mixed $source, MappingOptions $options): bool
	{
		return $source instanceof stdClass && isset($source->displayName);
	}

	public function map(MappingBranch $context): mixed
	{
		/** @var stdClass $source */
		$source = $context->getSource();
		++$this->reads;
		$context->write('name', $source->displayName);
		$context->write('self', $source->self);

		return $context->getResult();
	}
}
