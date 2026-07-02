<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use InvalidArgumentException;
use ON\Data\Query\QuerySourceInterface;

final class SourceFieldExpression extends AbstractAggregateableExpression
{
	public function __construct(
		private readonly QuerySourceInterface $source,
		private readonly string $name,
	) {
	}

	public function getSource(): QuerySourceInterface
	{
		return $this->source;
	}

	public function getName(): string
	{
		return $this->name;
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
			$this->name,
		];
	}

	public function bindTo(QuerySourceInterface $target, ?QuerySourceInterface $from = null): self
	{
		if ($from === null) {
			throw new InvalidArgumentException('SourceFieldExpression::bindTo() requires an explicit source.');
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

		$field = $source->field($fieldName);

		return $field instanceof self ? $field : $this;
	}
}
