<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use ON\Data\Query\SourceMap;

interface ValueExpressionInterface
{
	public function getSelectionKey(): string;

	public function rebind(SourceMap $sources): self;
}
