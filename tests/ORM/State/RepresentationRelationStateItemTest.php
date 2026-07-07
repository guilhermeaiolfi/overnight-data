<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\RepresentationRelationStateItem;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class RepresentationRelationStateItemTest extends TestCase
{
	use OrmFixture;

	public function testExposesBindingOwnerRecordAndRelationName(): void
	{
		$users = $this->users();
		$owner = RecordState::new($users, ['name' => 'Ada']);
		$binding = new RepresentationRelationBinding(
			'posts',
			$users,
			'posts',
			new RepresentationBinding($this->posts()),
		);

		$item = new RepresentationRelationStateItem($binding, $owner, 'posts');

		self::assertSame('posts', $item->getPath());
		self::assertSame($binding, $item->getBinding());
		self::assertSame($owner, $item->getOwnerRecord());
		self::assertSame('posts', $item->getRelationName());
	}

	public function testItemOnlyCarriesBindingOwnerRecordAndRelationName(): void
	{
		$reflection = new ReflectionClass(RepresentationRelationStateItem::class);
		$constructor = $reflection->getConstructor();

		self::assertNotNull($constructor);
		self::assertSame(3, $constructor->getNumberOfParameters());
		self::assertSame(
			['binding', 'ownerRecord', 'relationName'],
			array_map(static fn ($parameter) => $parameter->getName(), $constructor->getParameters()),
		);
	}

	public function testRelationLoadStateAccessorIsNotExposed(): void
	{
		self::assertFalse(method_exists(RepresentationRelationStateItem::class, 'getLoadState'));
		self::assertFalse(property_exists(RepresentationRelationStateItem::class, 'loadState'));
	}
}
