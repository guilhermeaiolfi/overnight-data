<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Compiler\SelectQuery;

use ON\Data\ORM\Compiler\SelectQuery\ProjectionIdentityColumns;
use PHPUnit\Framework\TestCase;

final class ProjectionIdentityColumnsTest extends TestCase
{
	public function testReturnsNullByDefault(): void
	{
		self::assertNull((new ProjectionIdentityColumns())->get([], 'id'));
	}

	public function testStoresAndRetrievesBySourcePath(): void
	{
		$columns = new ProjectionIdentityColumns();
		$columns->add([], 'id', 'root_id');
		$columns->add(['company'], 'id', 'company_id');

		self::assertSame('root_id', $columns->get([], 'id'));
		self::assertSame('company_id', $columns->get(['company'], 'id'));
		self::assertNull($columns->get(['manager'], 'id'));
	}

	public function testStoresSameTerminalCollectionUnderDifferentSourcePaths(): void
	{
		$columns = new ProjectionIdentityColumns();
		$columns->add([], 'id', 'root_id');
		$columns->add(['manager'], 'id', 'manager_id');

		self::assertSame('root_id', $columns->get([], 'id'));
		self::assertSame('manager_id', $columns->get(['manager'], 'id'));
		self::assertNotSame($columns->get([], 'id'), $columns->get(['manager'], 'id'));
	}

	public function testNestedSourcePathsAreDistinct(): void
	{
		$columns = new ProjectionIdentityColumns();
		$columns->add(['company'], 'id', 'company_id');
		$columns->add(['company', 'owner'], 'id', 'owner_id');

		self::assertSame('company_id', $columns->get(['company'], 'id'));
		self::assertSame('owner_id', $columns->get(['company', 'owner'], 'id'));
	}
}
