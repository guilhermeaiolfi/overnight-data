<?php

declare(strict_types=1);

namespace Tests\ON\Data\Definition;

use ON\Data\Definition\Collection\Collection;
use ON\Data\Definition\Exception\InvalidDefinitionClassException;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Interface\TextareaInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\Definition\Relation\M2MThrough;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Definition\View\ViewDefinition;
use ON\Data\Definition\View\ViewField;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Fixture\CustomDisplay;
use Tests\ON\Data\Fixture\CustomInterface;
use Tests\ON\Data\Fixture\CustomOwnedRelation;
use Tests\ON\Data\Fixture\CustomRelationOptions;

final class FinalDefinitionsArchitectureTest extends TestCase
{
	public function testFieldMapRejectsNonArrayBackedImplementations(): void
	{
		$field = $this->createMock(FieldInterface::class);
		$field->method('getName')->willReturn('title');

		$this->expectException(InvalidDefinitionClassException::class);

		(new Registry())->collection('posts')->fields->set('title', $field);
	}

	public function testRelationMapRejectsNonArrayBackedImplementations(): void
	{
		$relation = $this->createMock(RelationInterface::class);
		$relation->method('getName')->willReturn('author');

		$this->expectException(InvalidDefinitionClassException::class);

		(new Registry())->collection('posts')->relations->set('author', $relation);
	}

	public function testDisplayAndInterfaceCreationStoreCompleteDefinitionsImmediately(): void
	{
		$field = (new Registry())->collection('posts')->field('title', 'string');

		$field->display(CustomDisplay::class)->type('badge')->color('green')->end();
		$field->interface(CustomInterface::class)->limit(80)->end();

		self::assertSame(
			CustomDisplay::createDefinition([
				'type' => 'badge',
				'color' => 'green',
			]),
			$field->all()['display'],
		);
		self::assertSame(
			CustomInterface::createDefinition([
				'limit' => 80,
			]),
			$field->all()['interface'],
		);
	}

	public function testStrictRestorationDefersInvalidNestedClassFailuresUntilAccess(): void
	{
		$registry = new Registry([
			'collections' => [
				'users' => [
					'class' => Collection::class,
					'name' => 'users',
					'table' => 'users',
					'fields' => [
						'id' => ['name' => 'id', 'type' => 'int'],
					],
					'relations' => [
						'manager' => [
							'class' => stdClass::class,
							'name' => 'manager',
						],
					],
				],
			],
			'views' => [
				'summary' => [
					'class' => ViewDefinition::class,
					'name' => 'summary',
					'fields' => [
						'title' => [
							'class' => ViewField::class,
							'name' => 'title',
							'type' => 'string',
							'display' => ['class' => stdClass::class],
							'interface' => ['class' => stdClass::class],
						],
					],
				],
			],
		]);

		self::assertNotNull($registry->getCollection('users'));
		self::assertNotNull($registry->getView('summary'));

		try {
			$registry->getCollection('users')?->getField('id');
			self::fail('Expected invalid field class discriminator to fail on access.');
		} catch (InvalidDefinitionClassException) {
		}

		try {
			$registry->getCollection('users')?->getRelation('manager');
			self::fail('Expected invalid relation class discriminator to fail on access.');
		} catch (InvalidDefinitionClassException) {
		}

		try {
			$registry->getView('summary')?->getField('title')?->getDisplay();
			self::fail('Expected invalid display class discriminator to fail on access.');
		} catch (InvalidDefinitionClassException) {
		}

		$this->expectException(InvalidDefinitionClassException::class);
		$registry->getView('summary')?->getField('title')?->getInterface();
	}

	public function testManyToManyThroughRestoresWithoutMutatingDefinitionArray(): void
	{
		$registry = new Registry();
		$registry->collection('article')
			->primaryKey('id')
			->field('id', 'int')->end()
			->end();
		$registry->collection('tag')
			->primaryKey('id')
			->field('id', 'int')->end()
			->end();
		$registry->collection('article_tag')
			->field('article_id', 'int')->end()
			->field('tag_id', 'int')->end()
			->end();

		$relation = $registry->getCollection('article')
			?->relation('tags', M2MRelation::class)
			->collection('tag')
			->innerKey('id')
			->outerKey('id')
			->through('article_tag')
				->innerKey('article_id')
				->outerKey('tag_id')
				->end();

		self::assertInstanceOf(M2MRelation::class, $relation);

		$all = $registry->all();
		$restored = new Registry($all);
		$before = $restored->all();
		$restoredRelation = $restored->getCollection('article')?->getRelation('tags');

		self::assertInstanceOf(M2MRelation::class, $restoredRelation);
		self::assertInstanceOf(M2MThrough::class, $restoredRelation->through);
		self::assertSame('article_tag', $restoredRelation->through->getCollectionName());
		self::assertSame(['article_id'], $restoredRelation->through->throughInnerKeys());
		self::assertSame(['tag_id'], $restoredRelation->through->throughOuterKeys());
		self::assertSame($before, $restored->all());
	}

	public function testCustomOwnedRelationRoundTripsItsNestedChildWithoutRegistrySpecialCases(): void
	{
		$registry = new Registry();
		$registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('group_id', 'int')->end()
			->end();
		$registry->collection('groups')
			->primaryKey('id')
			->field('id', 'int')->end()
			->end();

		$relation = $registry->getCollection('users')
			?->relation('groups', CustomOwnedRelation::class)
			->collection('groups')
			->innerKey('group_id')
			->outerKey('id');

		self::assertInstanceOf(CustomOwnedRelation::class, $relation);
		$relation->options()->strategy('prefetch')->flags(['visible' => true])->end();
		$relation->display(CustomDisplay::class)->type('relation')->end();
		$relation->interface(TextareaInterface::class)->limit(3)->end();

		$all = $registry->all();
		$restored = new Registry($all);
		$before = $restored->all();
		$restoredRelation = $restored->getCollection('users')?->getRelation('groups');

		self::assertInstanceOf(CustomOwnedRelation::class, $restoredRelation);
		self::assertInstanceOf(CustomRelationOptions::class, $restoredRelation->options());
		self::assertSame('prefetch', $restoredRelation->options()->getStrategy());
		self::assertSame(['visible' => true], $restoredRelation->options()->getFlags());
		self::assertSame($before, $restored->all());
	}
}
