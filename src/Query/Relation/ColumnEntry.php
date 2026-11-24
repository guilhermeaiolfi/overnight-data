<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\SelectQuery;

final class ColumnEntry
{
	public function __construct(
		private readonly string $sqlAlias,
		private readonly SelectQuery $query,
		private readonly LoadBranch $branch,
		private readonly string $bagKey,
		private readonly ?string $schemaPath,
		private readonly ColumnRole $role,
		private readonly ValueExpressionInterface $expression,
	) {
	}

	public function getSqlAlias(): string
	{
		return $this->sqlAlias;
	}

	public function getQuery(): SelectQuery
	{
		return $this->query;
	}

	public function getBranch(): LoadBranch
	{
		return $this->branch;
	}

	public function getBagKey(): string
	{
		return $this->bagKey;
	}

	public function getSchemaPath(): ?string
	{
		return $this->schemaPath;
	}

	public function getRole(): ColumnRole
	{
		return $this->role;
	}

	public function getExpression(): ValueExpressionInterface
	{
		return $this->expression;
	}
}
