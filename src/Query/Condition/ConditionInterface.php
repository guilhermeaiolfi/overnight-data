<?php

declare(strict_types=1);

namespace ON\Data\Query\Condition;

use ON\Data\Query\SourceMap;

interface ConditionInterface
{
	public function rebind(SourceMap $sources): self;
}
