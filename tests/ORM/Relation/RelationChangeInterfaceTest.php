<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Relation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Relation\RelationChangeInterface;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use PHPUnit\Framework\TestCase;

final class RelationChangeInterfaceTest extends TestCase
{
	public function testToManyRelationStateImplementsRelationChangeInterface(): void
	{
		self::assertInstanceOf(
			RelationChangeInterface::class,
			new ToManyRelationState(RecordState::new($this->users()), 'posts', new RepresentationBinding())
		);
	}

	public function testToOneRelationStateImplementsRelationChangeInterface(): void
	{
		self::assertInstanceOf(
			RelationChangeInterface::class,
			new ToOneRelationState(RecordState::new($this->users()), 'author', new RepresentationBinding())
		);
	}

	private function users(): CollectionInterface
	{
		return (new Registry())->collection('users')->primaryKey('id')->field('id', 'int')->end();
	}
}
