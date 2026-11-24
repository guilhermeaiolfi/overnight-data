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
 * The expression payload and optional origin source are opaque here so Shape
 * does not depend on Query. Query compile stores a value expression and its
 * originating query level so reopening (`query($schema)`) can rebind references.
 */
final class RepresentationExpressionSchema
{
	public function __construct(
		private string $path,
		private object $expression,
		private ?object $originSource = null,
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

	public function getOriginSource(): ?object
	{
		return $this->originSource;
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
