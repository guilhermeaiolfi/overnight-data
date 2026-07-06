<?php

declare(strict_types=1);

namespace ON\Data\ORM\State;

/**
 * One scalar representation path bound to a RecordFieldRef with writability and
 * optional skip-when-missing adoption behavior.
 *
 * Exists as the leaf node of RepresentationBinding used by scalar sync and flat
 * projection adoption.
 */
use ON\Data\ORM\Exception\StateException;

final class RepresentationFieldBinding
{
	public function __construct(
		private string $path,
		private RecordFieldRef $field,
		private bool $writable = true,
		private bool $skipWhenMissing = false,
	) {
		if ($path === '') {
			throw new StateException('Representation binding path cannot be empty.');
		}
	}

	public function getPath(): string
	{
		return $this->path;
	}

	public function getField(): RecordFieldRef
	{
		return $this->field;
	}

	public function withField(RecordFieldRef $field): self
	{
		return new self($this->path, $field, $this->writable, $this->skipWhenMissing);
	}

	public function withSkipWhenMissing(bool $skipWhenMissing): self
	{
		return new self($this->path, $this->field, $this->writable, $skipWhenMissing);
	}

	public function isWritable(): bool
	{
		return $this->writable;
	}

	public function isReadOnly(): bool
	{
		return ! $this->writable;
	}

	public function shouldSkipWhenMissing(): bool
	{
		return $this->skipWhenMissing;
	}
}
