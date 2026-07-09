<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Relation;

use ON\Data\ORM\Relation\RelationStateInterface;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class RelationStateInterfaceTest extends TestCase
{
	use OrmFixture;

	public function testToManyRelationStateImplementsRelationStateInterface(): void
	{
		self::assertInstanceOf(
			RelationStateInterface::class,
			new ToManyRelationState(RecordState::new($this->users()), 'posts', new RepresentationSchema($this->users()))
		);
	}

	public function testToOneRelationStateImplementsRelationStateInterface(): void
	{
		self::assertInstanceOf(
			RelationStateInterface::class,
			new ToOneRelationState(RecordState::new($this->users()), 'author', new RepresentationSchema($this->users()))
		);
	}
}
