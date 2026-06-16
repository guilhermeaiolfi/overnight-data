<?php

declare(strict_types=1);

namespace Tests\ON\Data\Definition;

use ON\Data\Definition\Exception\InvalidDefinitionDataException;
use ON\Data\Definition\Registry;
use ON\Data\Definition\View\ViewDefinition;
use ON\Data\Definition\View\ViewField;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Fixture\CustomCollection;
use Tests\ON\Data\Fixture\CustomDisplay;
use Tests\ON\Data\Fixture\CustomField;
use Tests\ON\Data\Fixture\CustomInterface;
use Tests\ON\Data\Fixture\CustomRelation;
use Tests\ON\Data\PlainDataAsserts;

final class RegistryStorageTest extends TestCase
{
	use PlainDataAsserts;

	public function testRegistryAllIsPlainDataAndRestoresCustomWrappers(): void
	{
		$registry = new Registry();
		$users = $registry->collection('users', CustomCollection::class);
		$users->table('app_users')->primaryKey('id')->metadata('domain', 'accounts');

		$id = $users->field('id', 'int', CustomField::class)
			->column('user_id')
			->required(true)
			->display(CustomDisplay::class)->type('badge')->color('green')->end()
			->interface(CustomInterface::class)->limit(32)->end()
			->metadata('kind', 'pk');

		$manager = $users->relation('manager', CustomRelation::class)
			->collection('users')
			->innerKey('id')
			->outerKey('id')
			->display(CustomDisplay::class)->type('relation')->color('blue')->end()
			->interface(CustomInterface::class)->limit(12)->end()
			->metadata('role', 'self');

		$all = $registry->all();

		self::assertPlainData($all);
		self::assertSame(CustomCollection::class, $all['collections']['users']['class']);
		self::assertSame(CustomField::class, $all['collections']['users']['fields']['id']['class']);
		self::assertSame(CustomRelation::class, $all['collections']['users']['relations']['manager']['class']);
		self::assertSame(CustomDisplay::class, $all['collections']['users']['fields']['id']['display']['class']);
		self::assertSame(CustomInterface::class, $all['collections']['users']['fields']['id']['interface']['class']);
		self::assertArrayNotHasKey('name', $all['collections']['users']);
		self::assertArrayNotHasKey('name', $all['collections']['users']['fields']['id']);
		self::assertArrayNotHasKey('name', $all['collections']['users']['relations']['manager']);

		$restored = new Registry($all);
		$restoredUsers = $restored->getCollection('users');

		self::assertNotNull($restoredUsers);
		self::assertSame($all, $restored->all());
		self::assertInstanceOf(CustomCollection::class, $restoredUsers);
		self::assertSame($id->getName(), $restoredUsers->getField('id')?->getName());
		self::assertSame($manager->getName(), $restoredUsers->getRelation('manager')?->getName());
		self::assertInstanceOf(CustomField::class, $restoredUsers->getField('id'));
		self::assertInstanceOf(CustomRelation::class, $restoredUsers->getRelation('manager'));
		self::assertInstanceOf(CustomDisplay::class, $restoredUsers->getField('id')->getDisplay());
		self::assertInstanceOf(CustomInterface::class, $restoredUsers->getField('id')->getInterface());
	}

	public function testWrappersStayBoundToMasterArrayWithStableIdentity(): void
	{
		$registry = new Registry();
		$field = $registry->collection('posts')
			->field('title', 'string')
			->required(true)
			->display(CustomDisplay::class)->type('headline')->color('red')->end()
			->interface(CustomInterface::class)->limit(80)->end();

		self::assertSame($registry->getCollection('posts'), $registry->getCollection('posts'));
		self::assertSame($field, $registry->getCollection('posts')?->getField('title'));
		self::assertSame($field->getDisplay(), $field->getDisplay());
		self::assertSame($field->getInterface(), $field->getInterface());

		$field->required(false);
		self::assertFalse($registry->all()['collections']['posts']['fields']['title']['required']);
	}

	public function testCanonicalRegistryArrayRoundTripsIdempotently(): void
	{
		$canonical = [
			'collections' => [
				' post.user ' => [
					'class' => CustomCollection::class,
					'table' => ' post.user ',
					'primaryKey' => [],
					'fields' => [
						'id' => ['class' => CustomField::class, 'type' => 'int'],
					],
					'relations' => [],
					'metadata' => [],
				],
			],
			'views' => [
				' report summary ' => [
					'class' => ViewDefinition::class,
					'source' => ' post.user ',
					'fields' => [
						'label' => ['class' => ViewField::class, 'type' => 'string'],
					],
					'relations' => [],
					'metadata' => [],
				],
			],
		];

		$restored = (new Registry($canonical))->all();

		self::assertSame($canonical, $restored);
		self::assertArrayHasKey(' post.user ', $restored['collections']);
		self::assertArrayHasKey(' report summary ', $restored['views']);
	}

	public function testRegistryExportRejectsObjectMetadata(): void
	{
		$registry = new Registry();
		$registry->collection('users')
			->metadata('payload', new stdClass());

		$this->expectException(InvalidDefinitionDataException::class);
		$registry->all();
	}
}
