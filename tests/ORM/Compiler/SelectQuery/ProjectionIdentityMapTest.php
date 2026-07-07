<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Compiler\SelectQuery;

use ON\Data\ORM\Compiler\SelectQuery\ProjectionIdentityMap;
use PHPUnit\Framework\TestCase;

final class ProjectionIdentityMapTest extends TestCase
{
	public function testIsEmptyByDefault(): void
	{
		self::assertTrue((new ProjectionIdentityMap())->isEmpty());
	}

	public function testStoresAndRetrievesBySourcePath(): void
	{
		$map = new ProjectionIdentityMap();
		$map->add([], 'id', 'root_id');
		$map->add(['company'], 'id', 'company_id');

		self::assertFalse($map->isEmpty());
		self::assertSame('root_id', $map->get([], 'id'));
		self::assertSame('company_id', $map->get(['company'], 'id'));
		self::assertNull($map->get(['manager'], 'id'));
	}

	public function testStoresSameTerminalCollectionUnderDifferentSourcePaths(): void
	{
		$map = new ProjectionIdentityMap();
		$map->add([], 'id', 'root_id');
		$map->add(['manager'], 'id', 'manager_id');

		self::assertSame('root_id', $map->get([], 'id'));
		self::assertSame('manager_id', $map->get(['manager'], 'id'));
		self::assertNotSame($map->get([], 'id'), $map->get(['manager'], 'id'));
	}

	public function testNestedSourcePathsAreDistinct(): void
	{
		$map = new ProjectionIdentityMap();
		$map->add(['company'], 'id', 'company_id');
		$map->add(['company', 'owner'], 'id', 'owner_id');

		self::assertSame('company_id', $map->get(['company'], 'id'));
		self::assertSame('owner_id', $map->get(['company', 'owner'], 'id'));
	}

	public function testAllExposesEntriesKeyedBySourcePath(): void
	{
		$map = new ProjectionIdentityMap();
		$map->add([], 'id', 'root_id');
		$map->add(['manager'], 'id', 'manager_id');

		self::assertSame([
			'' => ['id' => 'root_id'],
			'manager' => ['id' => 'manager_id'],
		], $map->all());
	}
}
