<?php

declare(strict_types=1);

namespace ON\Data\Query;

use InvalidArgumentException;
use ON\Data\Query\Exception\UnknownQueryFieldException;
use ON\Data\Query\Expression\SourceFieldExpression;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;

/**
 * Thin derived FROM source: projected columns of an inner {@see SelectQuery} under an alias.
 *
 * The inner query stays unaliased and owns joins/compilation. This wrapper only exposes
 * the subquery product to an outer {@see SelectQuery}.
 */
final class DerivedSelectQuery implements QuerySourceInterface
{
	private readonly string $alias;

	/** @var array<string, SourceFieldExpression> */
	private array $fieldRefs = [];

	private ?StarExpression $star = null;

	public function __construct(
		private readonly SelectQuery $inner,
		?string $alias = null,
	) {
		if ($alias !== null && trim($alias) === '') {
			throw new InvalidArgumentException('Derived query source aliases cannot be empty.');
		}

		$this->alias = $alias === null ? $this->generateAutoAlias() : trim($alias);
	}

	public function getInnerQuery(): SelectQuery
	{
		return $this->inner;
	}

	public function getQuery(): SelectQuery
	{
		return $this->inner;
	}

	public function getAlias(): string
	{
		return $this->alias;
	}

	public function requireAlias(): string
	{
		return $this->alias;
	}

	/**
	 * @return list<string>
	 */
	public function getPath(): array
	{
		return [$this->alias];
	}

	public function field(string $name): ValueExpressionInterface
	{
		$name = trim($name);

		if ($name === '') {
			throw new InvalidArgumentException('DerivedSelectQuery::field() requires a non-empty field name.');
		}

		if (! DerivedOutputColumns::exposes($this->inner, $name)) {
			throw UnknownQueryFieldException::forDefinition($name, $this->alias);
		}

		return $this->fieldRefs[$name] ??= new SourceFieldExpression($this, $name);
	}

	public function __get(string $name): ValueExpressionInterface
	{
		return $this->field($name);
	}

	public function all(): StarExpression
	{
		return $this->star();
	}

	public function star(): StarExpression
	{
		return $this->star ??= new StarExpression($this);
	}

	/**
	 * @return list<string>
	 */
	public function selectionNames(): array
	{
		return DerivedOutputColumns::names($this->inner);
	}

	private function generateAutoAlias(): string
	{
		return 'derived_' . substr(sha1(spl_object_hash($this->inner)), 0, 8);
	}
}
