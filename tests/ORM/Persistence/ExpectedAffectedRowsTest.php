<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Persistence;

use ON\Data\ORM\Persistence\ExpectedAffectedRows;
use PHPUnit\Framework\TestCase;

final class ExpectedAffectedRowsTest extends TestCase
{
	public function testExactlyOneAcceptsOne(): void
	{
		$policy = ExpectedAffectedRows::exactly(1);

		self::assertTrue($policy->accepts(1));
	}

	public function testExactlyOneRejectsZero(): void
	{
		$policy = ExpectedAffectedRows::exactly(1);

		self::assertFalse($policy->accepts(0));
	}

	public function testExactlyOneRejectsTwo(): void
	{
		$policy = ExpectedAffectedRows::exactly(1);

		self::assertFalse($policy->accepts(2));
	}

	public function testZeroOrOneAcceptsZero(): void
	{
		$policy = ExpectedAffectedRows::zeroOrOne();

		self::assertTrue($policy->accepts(0));
	}

	public function testZeroOrOneAcceptsOne(): void
	{
		$policy = ExpectedAffectedRows::zeroOrOne();

		self::assertTrue($policy->accepts(1));
	}

	public function testZeroOrOneRejectsTwo(): void
	{
		$policy = ExpectedAffectedRows::zeroOrOne();

		self::assertFalse($policy->accepts(2));
	}

	public function testExactlyOneDescribe(): void
	{
		self::assertSame('1 row', ExpectedAffectedRows::exactly(1)->describe());
	}

	public function testZeroOrOneDescribe(): void
	{
		self::assertSame('0 or 1 rows', ExpectedAffectedRows::zeroOrOne()->describe());
	}
}
