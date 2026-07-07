<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use PHPUnit\Framework\TestCase;

final class RepresentationRelationBindingTest extends TestCase
{
	public function testExposesStructuralRelationData(): void
	{
		$users = $this->users();
		$relatedBinding = $this->related();
		$binding = new RepresentationRelationBinding('posts', $users, 'posts', $relatedBinding, true);

		self::assertSame('posts', $binding->getPath());
		self::assertSame($users, $binding->getOwnerCollection());
		self::assertSame('users', $binding->getOwnerCollectionName());
		self::assertSame('posts', $binding->getRelationName());
		self::assertSame($relatedBinding, $binding->getRelatedBinding());
		self::assertTrue($binding->shouldSkipWhenMissing());
	}

	public function testRejectsEmptyPath(): void
	{
		$this->expectException(StateException::class);

		new RepresentationRelationBinding('', $this->users(), 'posts', $this->related());
	}

	public function testRejectsEmptyRelationName(): void
	{
		$this->expectException(StateException::class);

		new RepresentationRelationBinding('posts', $this->users(), '', $this->related());
	}

	public function testDerivesCardinalityFromRelationDefinition(): void
	{
		$users = $this->users();
		$posts = new RepresentationRelationBinding('posts', $users, 'posts', $this->related());
		$profile = new RepresentationRelationBinding('profile', $users, 'profile', $this->related());

		self::assertTrue($posts->isMany());
		self::assertFalse($posts->isSingle());
		self::assertTrue($profile->isSingle());
		self::assertFalse($profile->isMany());
	}

	private function users(): \ON\Data\Definition\Collection\CollectionInterface
	{
		$registry = new Registry();
		$users = $registry->collection('users')->primaryKey('id')->field('id')->end();
		$users->hasMany('posts', 'posts');
		$users->hasOne('profile', 'profiles');

		return $users;
	}

	private function related(): RepresentationBinding
	{
		$registry = new Registry();
		$related = $registry->collection('posts')->primaryKey('id')->field('id')->end();

		return new RepresentationBinding($related);
	}
}
