<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query;

use ON\Data\Definition\Registry;
use ON\Data\Query\Condition\ConditionTag;
use function ON\Data\Query\x;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;

final class ConditionListTest extends TestCase
{
	public function testWhereAddsUserTaggedConditions(): void
	{
		$query = $this->query();
		$first = x()->eq($query->id, 1);
		$second = x()->eq($query->name, 'Ada');

		$query->where($first, $second);

		self::assertSame([$first, $second], $query->getConditions());
		self::assertCount(2, $query->getConditionList()->getByTag(ConditionTag::USER));
		self::assertSame([], $query->getConditionList()->getByTag(ConditionTag::CORRELATION));
	}

	public function testReplaceByTagSwapsOnlyMatchingTag(): void
	{
		$query = $this->query();
		$user = x()->eq($query->name, 'Ada');
		$firstCorrelation = x()->in($query->id, [1, 2]);
		$secondCorrelation = x()->in($query->id, [3, 4]);

		$query->where($user);
		$query->getConditionList()->replaceByTag(ConditionTag::CORRELATION, $firstCorrelation);
		$query->getConditionList()->replaceByTag(ConditionTag::CORRELATION, $secondCorrelation);

		self::assertSame([$user, $secondCorrelation], $query->getConditions());
		self::assertSame([$user], $query->getConditionList()->getConditionsByTag(ConditionTag::USER));
		self::assertSame([$secondCorrelation], $query->getConditionList()->getConditionsByTag(ConditionTag::CORRELATION));
	}

	public function testCopyPreservesConditionTags(): void
	{
		$query = $this->query();
		$query->where(x()->eq($query->name, 'Ada'));
		$query->getConditionList()->replaceByTag(
			ConditionTag::CORRELATION,
			x()->in($query->id, [1]),
		);

		$copy = $query->copy();

		self::assertCount(1, $copy->getConditionList()->getByTag(ConditionTag::USER));
		self::assertCount(1, $copy->getConditionList()->getByTag(ConditionTag::CORRELATION));
		self::assertNotSame(
			$query->getConditionList()->getConditionsByTag(ConditionTag::USER)[0],
			$copy->getConditionList()->getConditionsByTag(ConditionTag::USER)[0],
		);
	}

	private function query(): SelectQuery
	{
		$registry = new Registry();
		$users = $registry->collection('users');
		$users->field('id', 'int');
		$users->field('name', 'string');
		$users->primaryKey('id');

		return new SelectQuery($registry->getCollection('users'));
	}
}
