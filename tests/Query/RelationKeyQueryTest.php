<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query;

use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\RelationKeyPairing;
use ON\Data\Query\Condition\ComparisonCondition;
use ON\Data\Query\Condition\ComparisonOperator;
use ON\Data\Query\Condition\InCondition;
use ON\Data\Query\Condition\LogicalCondition;
use ON\Data\Query\Condition\LogicalOperator;
use ON\Data\Query\Expression\LiteralExpression;
use ON\Data\Query\JoinType;
use ON\Data\Query\Relation\RelationKeyQuery;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;

final class RelationKeyQueryTest extends TestCase
{
	public function testAddJoinConditionsAddsOneConditionPerPair(): void
	{
		$registry = $this->makeRegistry();
		$query = new SelectQuery($registry->getCollection('articles'));
		$join = $query->join($registry->getCollection('article_tags'), JoinType::INNER, 'article_tags');
		$pairing = RelationKeyPairing::from(['tenant_id', 'slug'], ['article_tenant_id', 'article_slug']);

		RelationKeyQuery::addJoinConditions($pairing, $join, $query);

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

		RelationKeyQuery::filterRightByLeftReferences($pairing, $query, $query, [
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

		RelationKeyQuery::filterRightByLeftReferences($pairing, $query, $query, [
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

		RelationKeyQuery::filterRightByLeftReferences(
			RelationKeyPairing::from(['id'], ['article_id']),
			$query,
			$query,
			[],
		);

		self::assertSame([], $query->getConditions());
	}

	public function testFilterRightByLeftReferencesAcceptsOrderedReferenceValues(): void
	{
		$registry = $this->makeRegistry();
		$query = new SelectQuery($registry->getCollection('article_tags'));
		$pairing = RelationKeyPairing::from(['tenant_id', 'slug'], ['article_tenant_id', 'article_slug']);

		RelationKeyQuery::filterRightByLeftReferences($pairing, $query, $query, [
			['__alias_1' => 1, '__alias_2' => 'hello'],
		]);

		$conditions = $query->getConditions();
		self::assertCount(1, $conditions);
		self::assertInstanceOf(LogicalCondition::class, $conditions[0]);
		$comparisons = $conditions[0]->getConditions()[0]->getConditions();

		self::assertSame(1, $comparisons[0]->getRight()->getValue());
		self::assertSame('hello', $comparisons[1]->getRight()->getValue());
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
}
