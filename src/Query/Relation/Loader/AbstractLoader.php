<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use LogicException;
use ON\Data\Query\Condition\ComparisonCondition;
use ON\Data\Query\Condition\ConditionInterface;
use ON\Data\Query\Condition\ExistsCondition;
use ON\Data\Query\Condition\InCondition;
use ON\Data\Query\Condition\LogicalCondition;
use ON\Data\Query\Condition\NotCondition;
use ON\Data\Query\Condition\NullCondition;
use ON\Data\Query\Exception\LoadRuntimeException;
use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\Expression\AggregateExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\SubqueryExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\Expression\ValueOperationExpression;
use ON\Data\Query\JoinType;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationKeyQuery;
use ON\Data\Query\Relation\RelationLoadBranch;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\SelectQuery;
use ON\Data\Query\Sort\Sort;

abstract class AbstractLoader implements LoaderInterface
{
	public function join(RelationRef $relation): QuerySourceInterface
	{
		$this->assertSupportedRelationPath($relation);
		$this->assertSupportedRelationConstraints($relation);

		$definition = $relation->getDefinition();
		$source = $relation->getParentSource();

		try {
			$keyPairing = $definition->getKeyPairing();
		} catch (LogicException) {
			throw RelationLoaderException::relationKeysIncomplete($relation);
		}

		$join = $relation->getQuery()->join(
			$definition->getCollection(),
			$definition->isNullable() ? JoinType::LEFT : JoinType::INNER,
			implode('.', $relation->getPath()),
			$source,
		);

		RelationKeyQuery::addJoinConditions($keyPairing, $join, $source);

		return $join;
	}

	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		throw RelationLoaderException::loadingNotImplemented($branch->getRelationRef());
	}

	final public function register(RelationLoadBranch $branch, LoadRuntime $runtime): AbstractNode
	{
		foreach ($branch->getChildren() as $child) {
			$runtime->registerBranch($child);
		}

		$node = $this->initNode($branch, $runtime);
		$attachmentNode = $node->getRelationAttachmentNode();

		foreach ($branch->getChildren() as $child) {
			if (! $child->hasNode()) {
				throw LoadRuntimeException::nodeNotRegistered($child->getRelationRef());
			}

			$childNode = $child->getNode();

			if ($child->isJoinedAttachment()) {
				$attachmentNode->joinNode($child->getRelationRef()->getName(), $childNode);

				continue;
			}

			$attachmentNode->linkNode($child->getRelationRef()->getName(), $childNode);
		}

		return $node;
	}

	abstract protected function initNode(RelationLoadBranch $branch, LoadRuntime $runtime): AbstractNode;

	protected function assertSupportedRelationPath(RelationRef $relation): void
	{
	}

	public function getDefaultLoadStrategy(): LoadStrategy
	{
		return LoadStrategy::SEPARATE_QUERY;
	}

	protected function assertSupportedRelationConstraints(RelationRef $relation): void
	{
		$definition = $relation->getDefinition();

		if ($definition->getWhere() !== []) {
			throw RelationLoaderException::relationWhereNotSupported($relation);
		}

		if ($definition->getOrderBy() !== []) {
			throw RelationLoaderException::relationOrderByNotSupported($relation);
		}
	}

	protected function assertNoJoinedSelectionOptions(RelationLoadBranch $branch): void
	{
		$selection = $branch->getSelection();
		$relation = $branch->getRelationRef();

		if ($selection->getConditions() !== []) {
			throw RelationLoaderException::relationWhereNotSupported($relation);
		}

		if ($selection->getSorts() !== []) {
			throw RelationLoaderException::relationOrderByNotSupported($relation);
		}
	}

	protected function applySeparateQueryOptions(RelationLoadBranch $branch): void
	{
		$query = $branch->getQuery();

		foreach ($branch->getSelection()->getConditions() as $condition) {
			$query->where($this->rebindCondition($condition, $branch, $query));
		}

		foreach ($branch->getSelection()->getSorts() as $sort) {
			$query->orderBy(new Sort(
				$this->rebindExpression($sort->getExpression(), $branch, $query),
				$sort->getDirection(),
			));
		}
	}

	private function rebindCondition(
		ConditionInterface $condition,
		RelationLoadBranch $branch,
		SelectQuery $query,
	): ConditionInterface {
		return match (true) {
			$condition instanceof ComparisonCondition => new ComparisonCondition(
				$this->rebindExpression($condition->getLeft(), $branch, $query),
				$condition->getOperator(),
				$this->rebindExpression($condition->getRight(), $branch, $query),
			),
			$condition instanceof NullCondition => new NullCondition(
				$this->rebindExpression($condition->getExpression(), $branch, $query),
				$condition->getOperator(),
			),
			$condition instanceof LogicalCondition => new LogicalCondition(
				$condition->getOperator(),
				array_map(
					fn (ConditionInterface $nested): ConditionInterface => $this->rebindCondition($nested, $branch, $query),
					$condition->getConditions(),
				),
			),
			$condition instanceof NotCondition => new NotCondition($this->rebindCondition($condition->getCondition(), $branch, $query)),
			$condition instanceof InCondition => new InCondition(
				$this->rebindExpression($condition->getExpression(), $branch, $query),
				is_array($condition->getSet())
					? array_map(
						fn (ValueExpressionInterface $item): ValueExpressionInterface => $this->rebindExpression($item, $branch, $query),
						$condition->getSet(),
					)
					: $condition->getSet(),
				$condition->isNegated(),
			),
			$condition instanceof ExistsCondition => $condition,
			default => $condition,
		};
	}

	private function rebindExpression(
		ValueExpressionInterface $expression,
		RelationLoadBranch $branch,
		SelectQuery $query,
	): ValueExpressionInterface {
		return match (true) {
			$expression instanceof FieldRef => $this->rebindFieldRef($expression, $branch, $query),
			$expression instanceof AggregateExpression => new AggregateExpression(
				$expression->getFunction(),
				$expression->getExpression() instanceof StarExpression
					? $expression->getExpression()
					: $this->rebindExpression($expression->getExpression(), $branch, $query),
			),
			$expression instanceof ValueOperationExpression => new ValueOperationExpression(
				$expression->getOperation(),
				array_map(
					fn (ValueExpressionInterface $argument): ValueExpressionInterface => $this->rebindExpression($argument, $branch, $query),
					$expression->getArguments(),
				),
			),
			$expression instanceof SubqueryExpression => $expression,
			default => $expression,
		};
	}

	private function rebindFieldRef(FieldRef $field, RelationLoadBranch $branch, SelectQuery $query): FieldRef
	{
		$branchPath = $branch->getRelationRef()->getPath();
		$fieldPath = $field->getPath();
		$prefix = array_slice($fieldPath, 0, count($branchPath));

		if ($prefix !== $branchPath) {
			return $field;
		}

		$relativePath = array_slice($fieldPath, count($branchPath));

		if ($relativePath === []) {
			return $field;
		}

		$fieldName = array_pop($relativePath);
		$source = $query;

		foreach ($relativePath as $relationName) {
			$source = $source->relation($relationName);
		}

		return $source->field($fieldName);
	}
}
