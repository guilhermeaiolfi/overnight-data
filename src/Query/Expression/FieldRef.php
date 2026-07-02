<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use InvalidArgumentException;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\SelectQuery;

final class FieldRef extends AbstractAggregateableExpression
{
	public function __construct(
		private readonly QuerySourceInterface $source,
		private readonly FieldInterface $field,
	) {
	}

	public function getSource(): QuerySourceInterface
	{
		return $this->source;
	}

	public function getQuery(): SelectQuery
	{
		return $this->source->getQuery();
	}

	public function getField(): FieldInterface
	{
		return $this->field;
	}

	public function getName(): string
	{
		return $this->field->getName();
	}

	public function getSelectionKey(): string
	{
		return implode('.', $this->getPath());
	}

	/**
	 * @return list<string>
	 */
	public function getPath(): array
	{
		return [
			...$this->source->getPath(),
			$this->getName(),
		];
	}

	public function bindTo(QuerySourceInterface $target, ?QuerySourceInterface $from = null): self|SourceFieldExpression
	{
		if ($from === null) {
			throw new InvalidArgumentException('FieldRef::bindTo() requires an explicit source.');
		}

		$fromPath = $from->getPath();
		$fieldPath = $this->getPath();

		if (array_slice($fieldPath, 0, count($fromPath)) !== $fromPath) {
			return $this;
		}

		$relativePath = array_slice($fieldPath, count($fromPath));

		if ($relativePath === []) {
			return $this;
		}

		$fieldName = array_pop($relativePath);
		$source = $target;

		foreach ($relativePath as $relationName) {
			$source = $source->relation($relationName);
		}

		return $source->field($fieldName);
	}
}
