<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use ON\Data\Query\QuerySourceInterface;

interface ValueExpressionInterface
{
	public function getSelectionKey(): string;

	public function bindTo(QuerySourceInterface $target, ?QuerySourceInterface $from = null): self;

	public function rebaseFields(QuerySourceInterface $from, QuerySourceInterface $to): self;
}
