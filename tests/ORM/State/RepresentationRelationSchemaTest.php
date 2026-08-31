<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Schema\Exception\InvalidSchemaException;
use ON\Data\Schema\RelationLoadKnowledge;
use ON\Data\Schema\RepresentationRelationSchema;
use ON\Data\Schema\RepresentationSchema;
use PHPUnit\Framework\TestCase;

final class RepresentationRelationSchemaTest extends TestCase
{
	public function testExposesStructuralRelationData(): void
	{
		$users = $this->users();
		$relatedSchema = $this->related();
		$schema = new RepresentationRelationSchema('posts', $users, 'posts', $relatedSchema, true);

		self::assertSame('posts', $schema->getPath());
		self::assertSame($users, $schema->getOwnerCollection());
		self::assertSame('users', $schema->getOwnerCollectionName());
		self::assertSame('posts', $schema->getRelationName());
		self::assertSame($relatedSchema, $schema->getRelatedSchema());
		self::assertTrue($schema->shouldSkipWhenMissing());
		self::assertSame(RelationLoadKnowledge::Unknown, $schema->getLoadKnowledge());
	}

	public function testRejectsEmptyPath(): void
	{
		$this->expectException(InvalidSchemaException::class);

		new RepresentationRelationSchema('', $this->users(), 'posts', $this->related());
	}

	public function testRejectsEmptyRelationName(): void
	{
		$this->expectException(InvalidSchemaException::class);

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

	private function users(): CollectionInterface
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
