<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\Sync\RepresentationValueReader;
use PHPUnit\Framework\TestCase;
use stdClass;

final class RepresentationValueReaderTest extends TestCase
{
	public function testReadsSimplePublicPropertyFromStdClass(): void
	{
		$row = new stdClass();
		$row->name = 'A1';

		self::assertSame(['name' => 'A1'], (new RepresentationValueReader())->read($row, $this->binding('name')));
	}

	public function testReadsSimplePublicPropertyFromPublicPropertyDto(): void
	{
		$row = new PublicPropertyUser();
		$row->name = 'A1';

		self::assertSame(['name' => 'A1'], (new RepresentationValueReader())->read($row, $this->binding('name')));
	}

	public function testReadsNestedStdClassPath(): void
	{
		$row = new stdClass();
		$row->author = new stdClass();
		$row->author->name = 'Ada';

		self::assertSame(['author.name' => 'Ada'], (new RepresentationValueReader())->read($row, $this->binding('author.name')));
	}

	public function testReadsNestedPublicPropertyObjectPath(): void
	{
		$row = new PublicPropertyPost();
		$row->author = new PublicPropertyUser();
		$row->author->name = 'Ada';

		self::assertSame(['author.name' => 'Ada'], (new RepresentationValueReader())->read($row, $this->binding('author.name')));
	}

	public function testPreservesNullAsPresentValue(): void
	{
		$row = new stdClass();
		$row->name = null;

		self::assertSame(['name' => null], (new RepresentationValueReader())->read($row, $this->binding('name')));
	}

	public function testMissingPropertyThrowsSyncException(): void
	{
		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('name');

		(new RepresentationValueReader())->read(new stdClass(), $this->binding('name'));
	}

	public function testMissingNestedPropertyThrowsSyncException(): void
	{
		$row = new stdClass();
		$row->author = new stdClass();

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('author.name');

		(new RepresentationValueReader())->read($row, $this->binding('author.name'));
	}

	public function testIntermediateNonObjectSegmentThrowsSyncException(): void
	{
		$row = new stdClass();
		$row->author = 'Ada';

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('author.name');

		(new RepresentationValueReader())->read($row, $this->binding('author.name'));
	}

	public function testReadsWritableAndReadOnlyBindings(): void
	{
		$row = new stdClass();
		$row->name = 'Ada';
		$row->upperName = 'ADA';
		$binding = new RepresentationBinding();
		$binding->add(new RepresentationFieldBinding('name', RecordFieldRef::template($this->users(), 'name')));
		$binding->add(new RepresentationFieldBinding('upperName', RecordFieldRef::template($this->users(), 'name'), false));

		self::assertSame(
			['name' => 'Ada', 'upperName' => 'ADA'],
			(new RepresentationValueReader())->read($row, $binding)
		);
	}

	public function testPreservesBindingInsertionOrder(): void
	{
		$row = new stdClass();
		$row->email = 'ada@example.test';
		$row->name = 'Ada';
		$binding = new RepresentationBinding();
		$binding->add(new RepresentationFieldBinding('email', RecordFieldRef::template($this->users(), 'email')));
		$binding->add(new RepresentationFieldBinding('name', RecordFieldRef::template($this->users(), 'name')));

		self::assertSame(
			['email' => 'ada@example.test', 'name' => 'Ada'],
			(new RepresentationValueReader())->read($row, $binding)
		);
	}

	public function testDoesNotMutateRepresentation(): void
	{
		$row = new stdClass();
		$row->name = 'Ada';
		$before = clone $row;

		(new RepresentationValueReader())->read($row, $this->binding('name'));

		self::assertEquals($before, $row);
	}

	public function testReadsNumericPathSegmentFromArrayOffset(): void
	{
		$row = new stdClass();
		$row->posts = [];
		$row->posts[0] = new stdClass();
		$row->posts[0]->title = 'Hello';

		self::assertSame(
			['posts.0.title' => 'Hello'],
			(new RepresentationValueReader())->read($row, $this->binding('posts.0.title'))
		);
	}

	private function binding(string $path): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		$binding->add(new RepresentationFieldBinding($path, RecordFieldRef::template($this->users(), 'name')));

		return $binding;
	}

	private function users(): CollectionInterface
	{
		return (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end()
			->field('email', 'string')->end();
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
