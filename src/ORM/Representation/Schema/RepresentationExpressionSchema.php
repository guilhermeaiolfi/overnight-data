<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema;

use ON\Data\ORM\Exception\StateException;
use ON\Data\Query\Expression\ValueExpressionInterface;

/**
 * Computed / non-column scalar place path on a representation shape.
 *
 * Always non-writable for Session sync and adoption; does not participate in
 * {@see RepresentationSource} grouping.
 */
final class RepresentationExpressionSchema
{
	public function __construct(
		private string $path,
		private ValueExpressionInterface $expression,
	) {
		if ($path === '') {
			throw new StateException('Representation expression schema path cannot be empty.');
		}
	}

	public function getPath(): string
	{
		return $this->path;
	}

	public function getExpression(): ValueExpressionInterface
	{
		return $this->expression;
	}

	public function isWritable(): bool
	{
		return false;
	}

	public function isReadOnly(): bool
	{
		return true;
	}
}
