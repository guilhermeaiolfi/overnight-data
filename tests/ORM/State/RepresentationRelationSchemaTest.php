<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RepresentationSchema;
use ON\Data\ORM\State\RepresentationRelationSchema;
use PHPUnit\Framework\TestCase;

final class RepresentationRelationSchemaTest extends TestCase
{
	public function testExposesStructuralRelationData(): void
	{
		$users = $this->users();
		$relatedSchema = $this->related();
		$binding = new RepresentationRelationSchema('posts', $users, 'posts', $relatedSchema, true);

		self::assertSame('posts', $binding->getPath());
		self::assertSame($users, $binding->getOwnerCollection());
		self::assertSame('users', $binding->getOwnerCollectionName());
		self::assertSame('posts', $binding->getRelationName());
		self::assertSame($relatedSchema, $binding->getRelatedSchema());
		self::assertTrue($binding->shouldSkipWhenMissing());
	}

	public function testRejectsEmptyPath(): void
	{
		$this->expectException(StateException::class);

		new RepresentationRelationSchema('', $this->users(), 'posts', $this->related());
	}

	public function testRejectsEmptyRelationName(): void
	{
		$this->expectException(StateException::class);

		new RepresentationRelationSchema('posts', $this->users(), '', $this->related());
	}

	public function testDerivesCardinalityFromRelationDefinition(): void
	{
		$users = $this->users();
		$posts = new RepresentationRelationSchema('posts', $users, 'posts', $this->related());
		$profile = new RepresentationRelationSchema('profile', $users, 'profile', $this->related());

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

	private function related(): RepresentationSchema
	{
		$registry = new Registry();
		$related = $registry->collection('posts')->primaryKey('id')->field('id')->end();

		return new RepresentationSchema($related);
	}
}
