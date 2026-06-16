<?php

declare(strict_types=1);

namespace Tests\ON\Data\Definition;

use ON\Data\Definition\Exception\InvalidDefinitionClassException;
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
		$registry = new class () extends Registry {
			public function &itemsReference(): array
			{
				return $this->getItemsReference();
			}
		};

		$users = new CustomCollection($registry);
		$users->name('users')->table('app_users')->primaryKey('id')->metadata('domain', 'accounts');
		$registry->register($users);

		$users->fields->set('id', new CustomField($users));
		$id = $users->fields->get('id');
		$id->name('id')
			->type('int')
			->column('user_id')
			->required(true)
			->display(CustomDisplay::class)->type('badge')->color('green')->end()
			->interface(CustomInterface::class)->limit(32)->end()
			->metadata('kind', 'pk');

		$users->relations->set('manager', new CustomRelation($users));
		$manager = $users->relations->get('manager');
		$manager->name('manager')
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
		self::assertSame(['id'], $all['collections']['users']['primaryKey']);
		self::assertArrayNotHasKey('pk', $all['collections']['users']['fields']['id']);

		$restored = new Registry($all);
		$restoredUsers = $restored->getCollection('users');

		self::assertNotNull($restoredUsers);
		self::assertSame($all, $restored->all());
		self::assertInstanceOf(CustomCollection::class, $restoredUsers);
		self::assertInstanceOf(CustomField::class, $restoredUsers->fields->get('id'));
		self::assertInstanceOf(CustomRelation::class, $restoredUsers->relations->get('manager'));
		self::assertInstanceOf(CustomDisplay::class, $restoredUsers->fields->get('id')->getDisplay());
		self::assertInstanceOf(CustomInterface::class, $restoredUsers->fields->get('id')->getInterface());
		self::assertSame(['id'], $restoredUsers->getPrimaryKey());
	}

	public function testWrappersStayBoundToMasterArrayWithStableIdentity(): void
	{
		$registry = new class () extends Registry {
			public function &itemsReference(): array
			{
				return $this->getItemsReference();
			}
		};

		$field = $registry->collection('posts')
			->field('title', 'string')
			->required(true)
			->display(CustomDisplay::class)->type('headline')->color('red')->end()
			->interface(CustomInterface::class)->limit(80)->end();

		self::assertSame($registry->getCollection('posts'), $registry->getCollection('posts'));
		self::assertSame($field, $registry->getCollection('posts')?->fields->get('title'));
		self::assertSame($field->getDisplay(), $field->getDisplay());
		self::assertSame($field->getInterface(), $field->getInterface());

		$field->required(false);
		self::assertFalse($registry->all()['collections']['posts']['fields']['title']['required']);

		$items = &$registry->itemsReference();
		$items['collections']['posts']['fields']['title']['required'] = true;
		$items['collections']['posts']['fields']['title']['display']['color'] = 'orange';

		self::assertTrue($field->isRequired());
		self::assertSame('orange', $field->getDisplay()->getColor());
	}

	public function testCanonicalRegistryArrayRoundTripsIdempotently(): void
	{
		$canonical = [
			'collections' => [
				' post.user ' => [
					'class' => CustomCollection::class,
					'name' => ' post.user ',
					'table' => ' post.user ',
					'primaryKey' => [],
					'fields' => [
						'id' => ['class' => CustomField::class, 'name' => 'id', 'type' => 'int'],
					],
					'relations' => [],
					'metadata' => [],
				],
			],
			'views' => [
				' report summary ' => [
					'class' => ViewDefinition::class,
					'name' => ' report summary ',
					'source' => ' post.user ',
					'fields' => [
						'label' => ['class' => ViewField::class, 'name' => 'label', 'type' => 'string'],
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

	public function testRegistryNormalizationRejectsInvalidStoredClassDiscriminatorsEarly(): void
	{
		$this->expectException(InvalidDefinitionClassException::class);

		new Registry([
			'collections' => [
				'users' => [
					'class' => stdClass::class,
					'name' => 'users',
				],
			],
		]);
	}

	public function testRelationDefinitionsRequireClassDiscriminatorDuringNormalization(): void
	{
		$this->expectException(InvalidDefinitionClassException::class);

		new Registry([
			'collections' => [
				'users' => [
					'name' => 'users',
					'relations' => [
						'manager' => [
							'name' => 'manager',
						],
					],
				],
			],
		]);
	}
}
