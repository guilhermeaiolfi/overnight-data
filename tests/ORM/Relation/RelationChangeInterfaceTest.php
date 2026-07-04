<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Relation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Relation\RelatedCollection;
use ON\Data\ORM\Relation\RelatedReference;
use ON\Data\ORM\Relation\RelationChangeInterface;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use PHPUnit\Framework\TestCase;

final class RelationChangeInterfaceTest extends TestCase
{
	public function testRelatedCollectionImplementsRelationChangeInterface(): void
	{
		self::assertInstanceOf(
			RelationChangeInterface::class,
			new RelatedCollection(RecordState::new($this->users()), 'posts', new RepresentationBinding())
		);
	}

	public function testRelatedReferenceImplementsRelationChangeInterface(): void
	{
		self::assertInstanceOf(
			RelationChangeInterface::class,
			new RelatedReference(RecordState::new($this->users()), 'author', new RepresentationBinding())
		);
	}

	private function users(): CollectionInterface
	{
		return (new Registry())->collection('users')->primaryKey('id')->field('id', 'int')->end();
	}
}
