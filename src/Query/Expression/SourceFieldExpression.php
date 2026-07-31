<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\SourceMap;

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

	public function rebind(SourceMap $sources): self
	{
		$source = $sources->remap($this->source);

		return $source === $this->source ? $this : new self($source, $this->name);
	}
}
