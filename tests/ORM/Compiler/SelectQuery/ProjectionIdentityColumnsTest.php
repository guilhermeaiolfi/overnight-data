<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Compiler\SelectQuery;

use ON\Data\ORM\Representation\Schema\Query\QuerySourceIdentities;
use PHPUnit\Framework\TestCase;

final class ProjectionIdentityColumnsTest extends TestCase
{
	public function testReturnsNullByDefault(): void
	{
		self::assertNull((new QuerySourceIdentities([]))->getResultKey([], 'id'));
	}

	public function testStoresAndRetrievesBySourcePath(): void
	{
		$identities = new QuerySourceIdentities([]);
		$identities->add([], 'id', 'root_id');
		$identities->add(['company'], 'id', 'company_id');

		self::assertSame('root_id', $identities->getResultKey([], 'id'));
		self::assertSame('company_id', $identities->getResultKey(['company'], 'id'));
		self::assertNull($identities->getResultKey(['manager'], 'id'));
	}

	public function testStoresSameTerminalCollectionUnderDifferentSourcePaths(): void
	{
		$identities = new QuerySourceIdentities([]);
		$identities->add([], 'id', 'root_id');
		$identities->add(['manager'], 'id', 'manager_id');

		self::assertSame('root_id', $identities->getResultKey([], 'id'));
		self::assertSame('manager_id', $identities->getResultKey(['manager'], 'id'));
		self::assertNotSame($identities->getResultKey([], 'id'), $identities->getResultKey(['manager'], 'id'));
	}

	public function testNestedSourcePathsAreDistinct(): void
	{
		$identities = new QuerySourceIdentities([]);
		$identities->add(['company'], 'id', 'company_id');
		$identities->add(['company', 'owner'], 'id', 'owner_id');

		self::assertSame('company_id', $identities->getResultKey(['company'], 'id'));
		self::assertSame('owner_id', $identities->getResultKey(['company', 'owner'], 'id'));
	}
}
