<?php

declare(strict_types=1);

namespace Tests\ON\Data\Definition;

use InvalidArgumentException;
use ON\Data\Definition\Collection\Collection;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Exception\DefinitionNameConflictException;
use ON\Data\Definition\Exception\DefinitionNotFoundException;
use ON\Data\Definition\Exception\ForeignRegistryDefinitionException;
use ON\Data\Definition\Exception\InvalidRelationParentException;
use ON\Data\Definition\Field\Field;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\HasOneRelation;
use ON\Data\Definition\View\ViewField;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\Fixture\CustomViewDefinition;
use Tests\ON\Data\Fixture\CustomViewField;
use Tests\ON\Data\Fixture\CustomViewRelation;

final class ViewDefinitionTest extends TestCase
{
	public function testRegistryNormalizesViewsRootAndSupportsSharedDefinitionLookup(): void
	{
		$registry = new Registry([
			'collections' => [
				'users' => [
					'name' => 'users',
					'table' => 'users',
				],
			],
		]);

		self::assertSame([], $registry->all()['views']);
		self::assertTrue($registry->hasCollection('users'));
		self::assertFalse($registry->hasView('users'));
		self::assertTrue($registry->hasDefinition('users'));
		self::assertInstanceOf(CollectionInterface::class, $registry->getDefinition('users'));
		self::assertNull($registry->getDefinition('missing'));
	}

	public function testCollectionAndViewNamesShareOneNamespace(): void
	{
		$registry = new Registry();
		$registry->collection('users');

		$this->expectException(DefinitionNameConflictException::class);
		$registry->view('users');
	}

	public function testRestoringConflictingCollectionAndViewNamesThrows(): void
	{
		$this->expectException(DefinitionNameConflictException::class);

		new Registry([
			'collections' => [
				'users' => [
					'name' => 'users',
					'table' => 'users',
				],
			],
			'views' => [
				'users' => [
					'name' => 'users',
				],
			],
		]);
	}

	public function testViewApiSupportsStableWrappersAndRoundTrip(): void
	{
		$registry = new Registry();
		$registry->collection('users')->field('id', 'int')->end()->primaryKey('id');

		$view = $registry->view('user_summary');
		self::assertSame($view, $registry->getView('user_summary'));
		self::assertSame($view, $registry->getDefinition('user_summary'));
		self::assertSame(['user_summary'], array_keys($registry->getViews()));

		$field = $view->field('name', 'string')
			->alias('display_name')
			->metadata('label', 'Name');
		$view->metadata('group', 'accounts');
		$view->source('users');
		$view->relation('manager', CustomViewRelation::class)
			->collection('users')
			->innerKey('name')
			->outerKey('id');

		self::assertInstanceOf(ViewField::class, $field);
		self::assertSame($view, $field->getParent());
		self::assertSame($view, $field->end());
		self::assertFalse($field->isPrimaryKey());
		self::assertSame('users', $registry->all()['views']['user_summary']['source']);

		$all = $registry->all();
		$restored = new Registry($all);
		$restoredView = $restored->getView('user_summary');

		self::assertNotNull($restoredView);
		self::assertSame($all, $restored->all());
		self::assertSame($restoredView, $restored->getView('user_summary'));
		self::assertSame($restoredView?->getField('name'), $restoredView?->getField('name'));
		self::assertSame($restoredView?->getRelation('manager'), $restoredView?->getRelation('manager'));
		self::assertSame('users', $restoredView?->getSourceName());
		self::assertInstanceOf(CollectionInterface::class, $restoredView?->getSource());
	}

	public function testViewSupportsSourceByDefinitionAndDeferredResolution(): void
	{
		$registry = new Registry();
		$users = $registry->collection('users');
		$base = $registry->view('base_user');

		$summary = $registry->view('summary');
		$summary->source($users);
		self::assertSame('users', $summary->getSourceName());
		self::assertSame($users, $summary->getSource());

		$summary->source($base);
		self::assertSame('base_user', $summary->getSourceName());
		self::assertSame($base, $summary->getSource());

		$future = $registry->view('future');
		$future->source('later_view');
		self::assertTrue($future->hasSource());
		self::assertSame('later_view', $future->getSourceName());

		$this->expectException(DefinitionNotFoundException::class);
		$future->getSource();
	}

	public function testCollectionOnlyRelationRejectsViewParentExplicitly(): void
	{
		$registry = new Registry();
		$registry->collection('users')->field('id', 'int')->end()->primaryKey('id');
		$view = $registry->view('user_summary');

		$this->expectException(InvalidRelationParentException::class);
		$view->relation('manager', HasOneRelation::class);
	}

	public function testViewRejectsEmptyAndForeignSources(): void
	{
		$registry = new Registry();
		$foreign = (new Registry())->view('foreign');
		$view = $registry->view('local');

		try {
			$view->source('  ');
			self::fail('Expected empty source rejection.');
		} catch (InvalidArgumentException) {
		}

		$this->expectException(ForeignRegistryDefinitionException::class);
		$view->source($foreign);
	}

	public function testCustomViewSubclassesSurviveRoundTrip(): void
	{
		$restored = new Registry([
			'collections' => [
				'users' => [
					'class' => Collection::class,
					'name' => 'users',
					'table' => 'users',
					'primaryKey' => ['id'],
					'fields' => [
						'id' => [
							'class' => Field::class,
							'name' => 'id',
							'type' => 'int',
						],
					],
					'relations' => [],
					'metadata' => [],
				],
			],
			'views' => [
				'custom_view' => [
					'class' => CustomViewDefinition::class,
					'name' => 'custom_view',
					'source' => 'users',
					'fields' => [
						'title' => [
							'class' => CustomViewField::class,
							'name' => 'title',
							'type' => 'string',
						],
					],
					'relations' => [],
					'metadata' => [],
				],
			],
		]);
		$view = $restored->getView('custom_view');

		self::assertInstanceOf(CustomViewDefinition::class, $view);
		self::assertInstanceOf(CustomViewField::class, $view?->getField('title'));
	}

	public function testClonedViewDetachesFromRegistryMasterArray(): void
	{
		$registry = new Registry();
		$view = $registry->view('user_summary');
		$view->field('name', 'string')->metadata('label', 'Original');

		$clone = clone $view;
		$clone->field('name')->metadata('label', 'Clone');

		self::assertSame('Original', $registry->all()['views']['user_summary']['fields']['name']['metadata']['label']);
		self::assertSame('Clone', $clone->all()['fields']['name']['metadata']['label']);
	}

	#[DataProvider('definitionProvider')]
	public function testCommonDefinitionContractWorksForCollectionsAndViews(DefinitionInterface $definition): void
	{
		self::assertInstanceOf(Registry::class, $definition->getRegistry());
		self::assertSame($definition, $definition->getRegistry()->getDefinition($definition));

		$field = $definition->field('title', 'string');
		self::assertInstanceOf(FieldInterface::class, $field);
		self::assertSame($field, $definition->getField('title'));
		self::assertTrue($definition->hasField('title'));

		$relation = $definition->relation('related', CustomViewRelation::class);
		self::assertSame($relation, $definition->getRelation('related'));
		self::assertTrue($definition->hasRelation('related'));

		$definition->metadata('label', 'Label');
		self::assertSame('Label', $definition->metadata('label'));
		self::assertSame($definition->getRegistry(), $definition->end());
	}

	/**
	 * @return iterable<string, array{DefinitionInterface}>
	 */
	public static function definitionProvider(): iterable
	{
		$registry = new Registry();
		$registry->collection('users');
		$registry->view('user_summary');

		yield 'collection' => [$registry->getCollection('users')];
		yield 'view' => [$registry->getView('user_summary')];
	}
}
