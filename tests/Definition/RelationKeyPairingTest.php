<?php

declare(strict_types=1);

namespace Tests\ON\Data\Definition;

use InvalidArgumentException;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\RelationKeyPairing;
use ON\Data\Query\Condition\ComparisonCondition;
use ON\Data\Query\Condition\ComparisonOperator;
use ON\Data\Query\Condition\InCondition;
use ON\Data\Query\Condition\LogicalCondition;
use ON\Data\Query\Condition\LogicalOperator;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\LiteralExpression;
use ON\Data\Query\JoinType;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;

final class RelationKeyPairingTest extends TestCase
{
	public function testFromPreservesOrderedPairs(): void
	{
		$pairing = RelationKeyPairing::from(['tenant_id', 'slug'], ['article_tenant_id', 'article_slug']);

		self::assertSame(['tenant_id', 'slug'], $pairing->getLeftFields());
		self::assertSame(['article_tenant_id', 'article_slug'], $pairing->getRightFields());
		self::assertSame([
			['left' => 'tenant_id', 'right' => 'article_tenant_id'],
			['left' => 'slug', 'right' => 'article_slug'],
		], $pairing->getPairs());
		self::assertSame(2, $pairing->count());
		self::assertTrue($pairing->isComposite());
	}

	public function testFromRejectsMismatchedCounts(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('count mismatch');

		RelationKeyPairing::from(['id'], ['tenant_id', 'slug']);
	}

	public function testFromRejectsEmptySides(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('at least one left field');

		RelationKeyPairing::from([], ['id']);
	}

	public function testRequireLeftAndRequireRightPreserveOrder(): void
	{
		$pairing = RelationKeyPairing::from(['tenant_id', 'slug'], ['article_tenant_id', 'article_slug']);
		$registry = $this->makeRegistry();
		$query = new SelectQuery($registry->getCollection('articles'));

		self::assertSame(['tenant_id', 'slug'], $pairing->requireLeft($this->branch($query)));
		self::assertSame(['article_tenant_id', 'article_slug'], $pairing->requireRight($this->branch(new SelectQuery($registry->getCollection('article_tags')))));
	}

	public function testReverseSwapsSidesAndIsCached(): void
	{
		$pairing = RelationKeyPairing::from(['tenant_id', 'slug'], ['article_tenant_id', 'article_slug']);
		$reversed = $pairing->reverse();

		self::assertSame(['article_tenant_id', 'article_slug'], $reversed->getLeftFields());
		self::assertSame(['tenant_id', 'slug'], $reversed->getRightFields());
		self::assertSame($reversed, $pairing->reverse());
		self::assertSame($pairing, $reversed->reverse());
	}

	public function testAddJoinConditionsAddsOneConditionPerPair(): void
	{
		$registry = $this->makeRegistry();
		$query = new SelectQuery($registry->getCollection('articles'));
		$join = $query->join($registry->getCollection('article_tags'), JoinType::INNER, 'article_tags');
		$pairing = RelationKeyPairing::from(['tenant_id', 'slug'], ['article_tenant_id', 'article_slug']);

		$pairing->addJoinConditions($join, $query);

		$conditions = $join->getConditions();
		self::assertCount(2, $conditions);

		foreach ($conditions as $index => $condition) {
			self::assertInstanceOf(ComparisonCondition::class, $condition);
			self::assertSame(ComparisonOperator::EQ, $condition->getOperator());
			self::assertSame($pairing->getLeftFields()[$index], $condition->getLeft()->getName());
			self::assertSame($pairing->getRightFields()[$index], $condition->getRight()->getName());
		}
	}

	public function testFilterRightByLeftReferencesUsesInForSinglePair(): void
	{
		$registry = $this->makeRegistry();
		$query = new SelectQuery($registry->getCollection('article_tags'));
		$pairing = RelationKeyPairing::from(['id'], ['article_id']);

		$pairing->filterRightByLeftReferences($query, $query, [
			['id' => 10],
			['id' => 11],
		]);

		$conditions = $query->getConditions();
		self::assertCount(1, $conditions);
		self::assertInstanceOf(InCondition::class, $conditions[0]);
		self::assertSame('article_id', $conditions[0]->getExpression()->getName());
		self::assertSame([10, 11], array_map(
			static fn (LiteralExpression $expression): mixed => $expression->getValue(),
			$conditions[0]->getSet(),
		));
	}

	public function testFilterRightByLeftReferencesUsesOrOfAndForCompositePairs(): void
	{
		$registry = $this->makeRegistry();
		$query = new SelectQuery($registry->getCollection('article_tags'));
		$pairing = RelationKeyPairing::from(['tenant_id', 'slug'], ['article_tenant_id', 'article_slug']);

		$pairing->filterRightByLeftReferences($query, $query, [
			['tenant_id' => 1, 'slug' => 'hello'],
			['tenant_id' => 2, 'slug' => 'world'],
		]);

		$conditions = $query->getConditions();
		self::assertCount(1, $conditions);
		self::assertInstanceOf(LogicalCondition::class, $conditions[0]);
		self::assertSame(LogicalOperator::OR, $conditions[0]->getOperator());
		self::assertCount(2, $conditions[0]->getConditions());

		foreach ($conditions[0]->getConditions() as $index => $predicate) {
			self::assertInstanceOf(LogicalCondition::class, $predicate);
			self::assertSame(LogicalOperator::AND, $predicate->getOperator());
			self::assertCount(2, $predicate->getConditions());

			foreach ($predicate->getConditions() as $pairIndex => $comparison) {
				self::assertInstanceOf(ComparisonCondition::class, $comparison);
				self::assertSame(ComparisonOperator::EQ, $comparison->getOperator());
				self::assertSame($pairing->getRightFields()[$pairIndex], $comparison->getLeft()->getName());
				self::assertSame(
					[
						['tenant_id' => 1, 'slug' => 'hello'],
						['tenant_id' => 2, 'slug' => 'world'],
					][$index][$pairing->getLeftFields()[$pairIndex]],
					$comparison->getRight()->getValue(),
				);
			}
		}
	}

	public function testEmptyReferencesAddNoFilter(): void
	{
		$registry = $this->makeRegistry();
		$query = new SelectQuery($registry->getCollection('article_tags'));

		RelationKeyPairing::from(['id'], ['article_id'])->filterRightByLeftReferences($query, $query, []);

		self::assertSame([], $query->getConditions());
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();

		$registry->collection('articles')
			->field('id', 'int')->end()
			->field('tenant_id', 'int')->end()
			->field('slug', 'string')->end()
			->primaryKey('id');

		$registry->collection('article_tags')
			->field('article_id', 'int')->end()
			->field('article_tenant_id', 'int')->end()
			->field('article_slug', 'string')->end()
			->field('tag_id', 'int')->end();

		return $registry;
	}

	private function branch(SelectQuery $query): object
	{
		return new class($query) extends \ON\Data\Query\Relation\LoadBranch
		{
			public function __construct(private readonly SelectQuery $query)
			{
			}

			public function getCollection(): \ON\Data\Definition\Collection\CollectionInterface
			{
				return $this->query->getCollection();
			}

			public function requireFields(array $fieldNames): array
			{
				return $fieldNames;
			}
		};
	}
}
