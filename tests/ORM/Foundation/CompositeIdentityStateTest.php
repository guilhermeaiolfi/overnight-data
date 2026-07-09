<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Foundation;

use ON\Data\Definition\Registry;
use ON\Data\Key;
use PHPUnit\Framework\TestCase;

final class CompositeIdentityStateTest extends TestCase
{
	public function testKeySupportsCompositeRecordIdentitiesInDefinitionOrder(): void
	{
		$memberships = (new Registry())
			->collection('memberships')
			->primaryKey('tenant_id', 'user_id')
			->field('tenant_id', 'int')->column('tenant_ref')->end()
			->field('user_id', 'int')->column('user_ref')->end();

		$key = $memberships->getKey(['user_ref' => 20, 'tenant_id' => 10]);

		self::assertInstanceOf(Key::class, $key);
		self::assertSame(['tenant_id' => 10, 'user_id' => 20], $key->getValues());
		self::assertSame('memberships#tenant_id=10,user_id=20', $key->getDebugString());
		self::assertSame(
			$key->getHash(),
			$memberships->getKey(['tenant_ref' => 10, 'user_id' => 20])->getHash(),
		);
	}
}
