<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query\Result\Parser;

use ON\Data\Query\Result\Parser\ParserException;
use ON\Data\Query\Result\Parser\ReferenceIndex;
use PHPUnit\Framework\TestCase;

final class ReferenceIndexTest extends TestCase
{
	public function testDistinctRawReferenceValuesAreReturnedOnce(): void
	{
		$index = new ReferenceIndex(['tenant_id', 'id']);
		$first = ['tenant_id' => 10, 'id' => 1, 'name' => 'Ada'];
		$second = ['tenant_id' => 10, 'id' => 1, 'name' => 'Ada duplicate'];
		$third = ['tenant_id' => 10, 'id' => 2, 'name' => 'Linus'];

		$index->add($first);
		$index->add($second);
		$index->add($third);

		self::assertSame([
			['tenant_id' => 10, 'id' => 1],
			['tenant_id' => 10, 'id' => 2],
		], $index->getReferenceValues());
	}

	public function testMultipleParentRecordsCanShareTheSameReferenceValues(): void
	{
		$index = new ReferenceIndex(['tenant_id']);
		$first = ['tenant_id' => 10, 'id' => 1];
		$second = ['tenant_id' => 10, 'id' => 2];

		$index->add($first);
		$index->add($second);

		self::assertSame(2, $index->getRecordCount(['tenant_id' => 10]));
		self::assertSame([$first, $second], $index->getRecords(['tenant_id' => 10]));
	}

	public function testLastReferenceValuesTracksTheMostRecentlyIndexedRecord(): void
	{
		$index = new ReferenceIndex(['tenant_id', 'id']);
		$record = ['tenant_id' => 10, 'id' => 2];

		$index->add($record);

		self::assertSame(['tenant_id' => 10, 'id' => 2], $index->getLastReferenceValues());
	}

	public function testNullReferenceComponentSkipsIndexing(): void
	{
		$index = new ReferenceIndex(['tenant_id', 'id']);
		$record = ['tenant_id' => 10, 'id' => null];

		$index->add($record);

		self::assertSame([], $index->getReferenceValues());
		self::assertNull($index->getLastReferenceValues());
	}

	public function testNonScalarReferenceComponentIsRejected(): void
	{
		$this->expectException(ParserException::class);

		$index = new ReferenceIndex(['tenant_id']);
		$record = ['tenant_id' => ['bad']];

		$index->add($record);
	}

	public function testMissingNamedReferenceFieldIsRejected(): void
	{
		$this->expectException(ParserException::class);

		$index = new ReferenceIndex(['tenant_id', 'id']);
		$index->getRecords(['tenant_id' => 10]);
	}
}
