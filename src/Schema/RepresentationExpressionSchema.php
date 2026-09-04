<?php

declare(strict_types=1);

namespace ON\Data\Schema;

use ON\Data\Schema\Exception\InvalidSchemaException;

/**
 * Computed / non-column scalar path on a representation shape.
 *
 * Always non-writable for Session sync and adoption; does not participate in
 * {@see RepresentationSource} grouping.
 *
 * The expression payload is opaque here so Shape does not depend on Query.
 * Query compile stores a value expression; assemble uses the path only.
 */
final class RepresentationExpressionSchema
{
	public function __construct(
		private string $path,
		private object $expression,
	) {
		if ($path === '') {
			throw new InvalidSchemaException('Representation expression schema path cannot be empty.');
		}
	}

	public function getPath(): string
	{
		return $this->path;
	}

	public function getExpression(): object
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
