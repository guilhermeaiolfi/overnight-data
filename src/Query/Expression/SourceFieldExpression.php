<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

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
}
