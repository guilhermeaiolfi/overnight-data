<?php

declare(strict_types=1);

namespace ON\Data\Query\Condition;

use ON\Data\Query\QuerySourceInterface;

interface ConditionInterface
{
	public function bindTo(QuerySourceInterface $target, ?QuerySourceInterface $from = null): self;
}
