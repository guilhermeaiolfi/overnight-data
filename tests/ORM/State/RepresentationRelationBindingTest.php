<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordRelationRef;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use PHPUnit\Framework\TestCase;

final class RepresentationRelationBindingTest extends TestCase
{
	public function testStoresPathRelationNameCardinalityRelatedBindingAndCollectionState(): void
	{
		$relatedBinding = new RepresentationBinding();
		$relation = RecordRelationRef::forCollection((new Registry())->collection('users'), 'posts');
		$binding = new RepresentationRelationBinding(
			'posts',
			$relation,
			RepresentationRelationCardinality::MANY,
			$relatedBinding,
			true
		);

		self::assertSame('posts', $binding->getPath());
		self::assertSame($relation, $binding->getRelation());
		self::assertSame('posts', $binding->getRelationName());
		self::assertSame(RepresentationRelationCardinality::MANY, $binding->getCardinality());
		self::assertSame($relatedBinding, $binding->getRelatedBinding());
		self::assertSame(true, $binding->isCollectionFullyLoaded());
	}

	public function testRejectsEmptyPath(): void
	{
		$this->expectException(StateException::class);
		$this->expectExceptionMessage('path');

		new RepresentationRelationBinding('', $this->relation('posts'), RepresentationRelationCardinality::MANY, new RepresentationBinding());
	}

	public function testManyCardinalityUsesRelatedBindingAsReusableItemShape(): void
	{
		$relatedBinding = new RepresentationBinding();
		$binding = new RepresentationRelationBinding(
			'posts',
			$this->relation('posts'),
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
			$this->relation('author'),
			RepresentationRelationCardinality::ONE,
			$relatedBinding
		);

		self::assertFalse($binding->isMany());
		self::assertTrue($binding->isSingle());
		self::assertSame($relatedBinding, $binding->getRelatedBinding());
	}

	public function testWithRelationReturnsCopyWithNewRelationRef(): void
	{
		$binding = new RepresentationRelationBinding(
			'posts',
			$this->relation('posts'),
			RepresentationRelationCardinality::MANY,
			new RepresentationBinding(),
			false
		);
		$nextRelation = RecordRelationRef::forCollection((new Registry())->collection('companies'), 'posts');

		$next = $binding->withRelation($nextRelation);

		self::assertNotSame($binding, $next);
		self::assertSame($nextRelation, $next->getRelation());
		self::assertSame($binding->getPath(), $next->getPath());
		self::assertSame($binding->getCardinality(), $next->getCardinality());
		self::assertSame($binding->getRelatedBinding(), $next->getRelatedBinding());
		self::assertSame($binding->isCollectionFullyLoaded(), $next->isCollectionFullyLoaded());
	}

	private function relation(string $relationName): RecordRelationRef
	{
		return RecordRelationRef::forCollection((new Registry())->collection('users'), $relationName);
	}
}
