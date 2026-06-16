<?php

declare(strict_types=1);

namespace Tests\ON\Data\Definition;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Fixture\CustomMapper;
use Tests\ON\Data\Fixture\CustomSource;

final class RegistryCollectionTest extends TestCase
{
	public function testRegistryTracksCollectionsAndDefinitionFiles(): void
	{
		$registry = new Registry();

		$users = $registry->collection('users');
		$posts = $registry->collection('posts');

		self::assertSame('users', $users->getName());
		self::assertSame('users', $users->getTable());
		self::assertSame($users, $registry->getCollection('users'));
		self::assertNull($registry->getCollection('missing'));
		self::assertSame(['users', 'posts'], array_keys($registry->getCollections()));

		$files = $registry->getDefinitionFiles();

		self::assertCount(1, $files);
		self::assertSame(['users', 'posts'], array_values($files)[0]);
	}

	public function testRepeatedCollectionCreationReturnsExistingWrapperAndRejectsConflictingClass(): void
	{
		$registry = new Registry();
		$original = $registry->collection('article');

		self::assertSame($original, $registry->collection('article'));
	}

	public function testCollectionCharacterizationIncludesStandaloneMapperAndSourceChanges(): void
	{
		$registry = new Registry();
		$collection = $registry->collection('report');

		self::assertNull($collection->getMapper());
		self::assertNull($collection->getSource());

		$collection
			->database('analytics')
			->entity(stdClass::class)
			->parentCollection('base_report')
			->scope('ReportScope')
			->repository('ReportRepository')
			->mapper(CustomMapper::class)
			->source(CustomSource::class)
			->note('report-note')
			->description('report-description')
			->hidden(true)
			->table('custom_reports');

		self::assertSame('analytics', $collection->getDatabase());
		self::assertSame(stdClass::class, $collection->getEntity());
		self::assertSame('base_report', $collection->getParentCollection());
		self::assertSame('ReportScope', $collection->getScope());
		self::assertSame('ReportRepository', $collection->getRepository());
		self::assertSame(CustomMapper::class, $collection->getMapper());
		self::assertSame(CustomSource::class, $collection->getSource());
		self::assertSame('report-note', $collection->getNote());
		self::assertSame('report-description', $collection->getDescription());
		self::assertTrue($collection->isHidden());
		self::assertSame('custom_reports', $collection->getTable());
		self::assertSame($registry, $collection->end());
		self::assertSame($registry, $collection->getRegistry());
	}

	public function testCollectionFieldAndRowHelpersPreserveLegacyBehavior(): void
	{
		$registry = new Registry();
		$collection = $registry->collection('post')->primaryKey('id');

		$collection->field('id', 'int')->column('post_id')->nullable(false)->end();
		$collection->field('title', 'string')->column('post_title')->end();
		$collection->field('secret', 'string')->column('secret_value')->hidden(true)->end();

		self::assertSame($collection->fields->get('id'), $collection->field('id'));
		self::assertTrue($collection->hasPrimaryKey());
		self::assertSame(['id'], $collection->getPrimaryKey());
		self::assertSame(['post_id'], $collection->getPrimaryKeyColumns());
		self::assertSame(['id', 'title'], $collection->getVisibleFields());
		self::assertSame(['post_id', 'post_title'], $collection->getVisibleColumns());
		self::assertSame('title', $collection->getFieldNameByColumn('post_title'));
		self::assertSame('unknown', $collection->getFieldNameByColumn('unknown'));
		self::assertSame(
			['id' => 10, 'title' => 'Hello', 'secret' => 'x'],
			$collection->mapRowFromColumns(['post_id' => 10, 'post_title' => 'Hello', 'secret_value' => 'x']),
		);
		self::assertSame(
			['id' => 10, 'title' => 'Hello'],
			$collection->mapVisibleRowFromColumns(['post_id' => 10, 'post_title' => 'Hello', 'secret_value' => 'x']),
		);
	}

	public function testGetPrimaryKeyFieldsAlwaysReturnsCanonicalArray(): void
	{
		$registry = new Registry();
		$single = $registry->collection('single')
			->primaryKey('id')
			->field('id', 'int')->end()
			->end()
			->getCollection('single');

		self::assertInstanceOf(CollectionInterface::class, $single);
		self::assertSame(['id'], $single->getPrimaryKey());
		self::assertCount(1, $single->getPrimaryKeyFields());
		self::assertSame('id', $single->getPrimaryKeyFields()[0]->getName());

		$composite = $registry->collection('composite')
			->primaryKey('tenant_id', 'slug')
			->field('tenant_id', 'int')->end()
			->field('slug', 'string')->end()
			->end()
			->getCollection('composite');

		$fields = $composite?->getPrimaryKeyFields();
		self::assertIsArray($fields);
		self::assertCount(2, $fields);
		self::assertSame(['tenant_id', 'slug'], array_map(static fn ($field) => $field->getName(), $fields));
	}
}
