<?php

declare(strict_types=1);

namespace ON\Data\Query\Condition;

use ON\Data\Query\QuerySourceInterface;

interface ConditionInterface
{
	public function rebaseFields(QuerySourceInterface $from, QuerySourceInterface $to): self;
}
