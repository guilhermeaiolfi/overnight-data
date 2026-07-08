<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationRelationSchema;
use ON\Data\ORM\State\RepresentationRelationStateItem;
use ON\Data\ORM\State\RepresentationSchema;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class RepresentationRelationStateItemTest extends TestCase
{
	use OrmFixture;

	public function testExposesSchemaOwnerRecordAndRelationName(): void
	{
		$users = $this->users();
		$owner = RecordState::new($users, ['name' => 'Ada']);
		$schema = new RepresentationRelationSchema(
			'posts',
			$users,
			'posts',
			new RepresentationSchema($this->posts()),
		);

		$item = new RepresentationRelationStateItem($schema, $owner, 'posts');

		self::assertSame('posts', $item->getPath());
		self::assertSame($schema, $item->getSchema());
		self::assertSame($owner, $item->getOwnerRecord());
		self::assertSame('posts', $item->getRelationName());
	}

	public function testItemOnlyCarriesSchemaOwnerRecordAndRelationName(): void
	{
		$reflection = new ReflectionClass(RepresentationRelationStateItem::class);
		$constructor = $reflection->getConstructor();

		self::assertNotNull($constructor);
		self::assertSame(3, $constructor->getNumberOfParameters());
		self::assertSame(
			['schema', 'ownerRecord', 'relationName'],
			array_map(static fn ($parameter) => $parameter->getName(), $constructor->getParameters()),
		);
	}

	public function testRelationLoadStateAccessorIsNotExposed(): void
	{
		self::assertFalse(method_exists(RepresentationRelationStateItem::class, 'getLoadState'));
		self::assertFalse(property_exists(RepresentationRelationStateItem::class, 'loadState'));
	}
}
