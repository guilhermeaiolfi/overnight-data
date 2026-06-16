<?php

declare(strict_types=1);

namespace Tests\ON\Data\Definition;

use InvalidArgumentException;
use LogicException;
use ON\Data\Definition\Exception\RelationException;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\BelongsToRelation;
use ON\Data\Definition\Relation\FirstOfManyRelation;
use ON\Data\Definition\Relation\HasManyRelation;
use ON\Data\Definition\Relation\HasOneRelation;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\Definition\Relation\RelationMap;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\Fixture\CustomRelation;

final class RelationDefinitionTest extends TestCase
{
	public function testSingleKeyRelationPreservesLegacyFieldAccess(): void
	{
		$registry = new Registry();
		$registry->collection('user')
			->primaryKey('id')
			->field('id', 'int')->end()
			->end();

		$relation = $registry->collection('post')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('user_id', 'int')->end()
			->belongsTo('author', 'user')->innerKey('user_id')->outerKey('id')->end()
			->relations->get('author');

		self::assertSame(['user_id'], $relation->innerKeys());
		self::assertSame(['id'], $relation->outerKeys());
		self::assertSame('user_id', $relation->getInnerField()->getName());
		self::assertSame('id', $relation->getOuterField()->getName());
		self::assertSame('single', $relation->getCardinality());
		self::assertFalse($relation->isJunction());
	}

	public function testCompositeRelationLegacyFieldAccessThrows(): void
	{
		$registry = new Registry();
		$registry->collection('page')
			->primaryKey('tenant_id', 'slug')
			->field('tenant_id', 'int')->end()
			->field('slug', 'string')->end()
			->end();

		$relation = $registry->collection('article')
			->field('tenant_id', 'int')->end()
			->field('page_slug', 'string')->end()
			->belongsTo('page', 'page')
				->innerKey(['tenant_id', 'page_slug'])
				->outerKey(['tenant_id', 'slug'])
			->end()
			->relations->get('page');

		self::assertSame(['tenant_id', 'page_slug'], $relation->innerKeys());
		self::assertSame(['tenant_id', 'slug'], $relation->outerKeys());

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('getInnerField() is only available for single-key relations');
		$relation->getInnerField();
	}

	public function testInvalidCompositeRelationDefinitionIsRejected(): void
	{
		$registry = new Registry();
		$registry->collection('page')
			->primaryKey('tenant_id', 'slug')
			->field('tenant_id', 'int')->end()
			->field('slug', 'string')->end()
			->end();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('key count mismatch');

		$registry->collection('article')
			->field('tenant_id', 'int')->end()
			->field('page_slug', 'string')->end()
			->belongsTo('page', 'page')
				->innerKey(['tenant_id'])
				->outerKey(['tenant_id', 'slug']);
	}

	public function testCompositeManyToManyThroughKeysAreNormalized(): void
	{
		$registry = new Registry();
		$registry->collection('article')
			->primaryKey('tenant_id', 'slug')
			->field('tenant_id', 'int')->end()
			->field('slug', 'string')->end()
			->end();
		$registry->collection('tag')
			->primaryKey('tenant_id', 'slug')
			->field('tenant_id', 'int')->end()
			->field('slug', 'string')->end()
			->end();
		$registry->collection('article_tag')
			->field('article_tenant_id', 'int')->end()
			->field('article_slug', 'string')->end()
			->field('tag_tenant_id', 'int')->end()
			->field('tag_slug', 'string')->end()
			->end();

		$relation = $registry->getCollection('article')
			?->relation('tags', M2MRelation::class)
			->collection('tag')
			->innerKey(['tenant_id', 'slug'])
			->outerKey(['tenant_id', 'slug'])
			->through('article_tag')
				->innerKey(['article_tenant_id', 'article_slug'])
				->outerKey(['tag_tenant_id', 'tag_slug'])
				->end();

		self::assertNotNull($relation);
		self::assertSame(['tenant_id', 'slug'], $relation->innerKeys());
		self::assertSame(['tenant_id', 'slug'], $relation->outerKeys());
		self::assertSame(['article_tenant_id', 'article_slug'], $relation->through->throughInnerKeys());
		self::assertSame(['tag_tenant_id', 'tag_slug'], $relation->through->throughOuterKeys());
		self::assertSame('many', $relation->getCardinality());
		self::assertTrue($relation->isJunction());
	}

	public function testConvenienceRelationsAndMapsWorkWithCustomSubclass(): void
	{
		$registry = new Registry();
		$registry->collection('profile')->primaryKey('id')->field('id', 'int')->end()->end();
		$collection = $registry->collection('user');
		$collection->primaryKey('id');
		$collection->field('id', 'int')->end();
		$collection->field('profile_id', 'int')->end();

		$hasMany = $collection->hasMany('profiles', 'profile');
		$hasOne = $collection->hasOne('main_profile', 'profile');
		$belongsTo = $collection->belongsTo('owner', 'profile');
		$custom = $collection->relation('custom', CustomRelation::class);

		self::assertInstanceOf(HasManyRelation::class, $hasMany);
		self::assertInstanceOf(HasOneRelation::class, $hasOne);
		self::assertInstanceOf(BelongsToRelation::class, $belongsTo);
		self::assertInstanceOf(CustomRelation::class, $custom);
		self::assertSame('profile', $hasMany->getCollectionName());

		$map = new RelationMap();
		$map->set('profiles', $hasMany);
		self::assertTrue($map->has('profiles'));
		self::assertSame($hasMany, $map->get('profiles'));
		self::assertSame(['profiles'], array_keys(iterator_to_array($map)));
		self::assertNotSame($map->get('profiles'), (clone $map)->get('profiles'));

		$this->expectException(RelationException::class);
		$map->set('profiles', $belongsTo);
	}

	public function testFirstOfManyRelationKeepsManyCardinalityWithoutJunction(): void
	{
		$relation = new FirstOfManyRelation((new Registry())->collection('user'));

		self::assertSame('single', $relation->getCardinality());
		self::assertFalse($relation->isJunction());
	}
}
