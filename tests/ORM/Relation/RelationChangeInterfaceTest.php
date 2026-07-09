<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Relation;

use ON\Data\ORM\Relation\RelationChangeInterface;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class RelationChangeInterfaceTest extends TestCase
{
	use OrmFixture;

	public function testToManyRelationStateImplementsRelationChangeInterface(): void
	{
		self::assertInstanceOf(
			RelationChangeInterface::class,
			new ToManyRelationState(RecordState::new($this->users()), 'posts', new RepresentationSchema($this->users()))
		);
	}

	public function testToOneRelationStateImplementsRelationChangeInterface(): void
	{
		self::assertInstanceOf(
			RelationChangeInterface::class,
			new ToOneRelationState(RecordState::new($this->users()), 'author', new RepresentationSchema($this->users()))
		);
	}
}
