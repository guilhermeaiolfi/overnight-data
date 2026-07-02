<?php

declare(strict_types=1);

namespace ON\Data\Query;

use InvalidArgumentException;
use ON\Data\Query\Expression\SourceFieldExpression;
use ON\Data\Query\Expression\StarExpression;

final class DerivedQuerySource implements QuerySourceInterface
{
	/**
	 * @var array<string, SourceFieldExpression>
	 */
	private array $fieldRefs = [];

	private ?StarExpression $star = null;

	public function __construct(
		private readonly SelectQuery $query,
		private readonly ?string $alias = null,
	) {
		if ($alias !== null && trim($alias) === '') {
			throw new InvalidArgumentException('Derived query source aliases cannot be empty.');
		}
	}

	public function getQuery(): SelectQuery
	{
		return $this->query;
	}

	public function getAlias(): ?string
	{
		return $this->alias === null ? null : trim($this->alias);
	}

	public function getPath(): array
	{
		return [$this->getAlias() ?? 'derived'];
	}

	public function field(string $name): SourceFieldExpression
	{
		$name = trim($name);

		if ($name === '') {
			throw new InvalidArgumentException('DerivedQuerySource::field() requires a non-empty field name.');
		}

		return $this->fieldRefs[$name] ??= new SourceFieldExpression($this, $name);
	}

	public function all(): StarExpression
	{
		return $this->star ??= new StarExpression($this);
	}

	public function star(): StarExpression
	{
		return $this->all();
	}
}
