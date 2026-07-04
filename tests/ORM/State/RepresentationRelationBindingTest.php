<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Relation\RelationCollectionState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use PHPUnit\Framework\TestCase;

final class RepresentationRelationBindingTest extends TestCase
{
	public function testStoresPathRelationNameCardinalityRelatedBindingAndCollectionState(): void
	{
		$relatedBinding = new RepresentationBinding();
		$binding = new RepresentationRelationBinding(
			'posts',
			'posts',
			RepresentationRelationCardinality::MANY,
			$relatedBinding,
			RelationCollectionState::FULLY_LOADED
		);

		self::assertSame('posts', $binding->getPath());
		self::assertSame('posts', $binding->getRelationName());
		self::assertSame(RepresentationRelationCardinality::MANY, $binding->getCardinality());
		self::assertSame($relatedBinding, $binding->getRelatedBinding());
		self::assertSame(RelationCollectionState::FULLY_LOADED, $binding->getCollectionState());
	}

	public function testRejectsEmptyPath(): void
	{
		$this->expectException(StateException::class);
		$this->expectExceptionMessage('path');

		new RepresentationRelationBinding('', 'posts', RepresentationRelationCardinality::MANY, new RepresentationBinding());
	}

	public function testRejectsEmptyRelationName(): void
	{
		$this->expectException(StateException::class);
		$this->expectExceptionMessage('relation name');

		new RepresentationRelationBinding('posts', '', RepresentationRelationCardinality::MANY, new RepresentationBinding());
	}

	public function testManyCardinalityUsesRelatedBindingAsReusableItemShape(): void
	{
		$relatedBinding = new RepresentationBinding();
		$binding = new RepresentationRelationBinding(
			'posts',
			'posts',
			RepresentationRelationCardinality::MANY,
			$relatedBinding
		);

		self::assertTrue($binding->isMany());
		self::assertFalse($binding->isSingle());
		self::assertSame($relatedBinding, $binding->getRelatedBinding());
	}

	public function testOneCardinalityUsesRelatedBindingAsReusableTargetShape(): void
	{
		$relatedBinding = new RepresentationBinding();
		$binding = new RepresentationRelationBinding(
			'author',
			'author',
			RepresentationRelationCardinality::ONE,
			$relatedBinding
		);

		self::assertFalse($binding->isMany());
		self::assertTrue($binding->isSingle());
		self::assertSame($relatedBinding, $binding->getRelatedBinding());
	}
}
