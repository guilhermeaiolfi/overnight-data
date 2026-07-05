<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordRelationRef;
use ON\Data\ORM\State\RecordState;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class RecordRelationRefTest extends TestCase
{
	use OrmFixture;

	public function testForCollectionCreatesTemplateRef(): void
	{
		$users = $this->users();
		$relation = RecordRelationRef::forCollection($users, 'posts');

		self::assertSame($users, $relation->getCollection());
		self::assertSame('users', $relation->getCollectionName());
		self::assertSame('posts', $relation->getRelationName());
		self::assertFalse($relation->hasState());
		self::assertTrue($relation->isTemplate());
		self::assertFalse($relation->hasConcreteRecord());
	}

	public function testForStateCreatesStateTargetedRef(): void
	{
		$state = RecordState::new($this->users(), ['name' => 'A1']);
		$relation = RecordRelationRef::forState($state, 'posts');

		self::assertSame($state->getCollection(), $relation->getCollection());
		self::assertSame('users', $relation->getCollectionName());
		self::assertSame('posts', $relation->getRelationName());
		self::assertTrue($relation->hasState());
		self::assertSame($state, $relation->getState());
		self::assertFalse($relation->isTemplate());
		self::assertTrue($relation->hasConcreteRecord());
		self::assertSame($state->getStateHash(), $relation->getRecordHash());
	}

	public function testRejectsEmptyRelationName(): void
	{
		$this->expectException(StateException::class);
		$this->expectExceptionMessage('relation name');

		RecordRelationRef::forCollection($this->users(), '');
	}

	public function testGetStateOnTemplateRefThrows(): void
	{
		$relation = RecordRelationRef::forCollection($this->users(), 'posts');

		$this->expectException(StateException::class);
		$relation->getState();
	}

	public function testGetRecordHashOnTemplateRefThrows(): void
	{
		$relation = RecordRelationRef::forCollection($this->users(), 'posts');

		$this->expectException(StateException::class);
		$relation->getRecordHash();
	}
}
