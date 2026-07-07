<?php

declare(strict_types=1);

namespace ON\Data\ORM\State;

use ON\Data\Definition\Collection\CollectionInterface;

/**
 * One structural scalar representation path with writability and optional
 * skip-when-missing adoption behavior.
 *
 * Exists as the leaf node of RepresentationBinding used by scalar sync and flat
 * projection adoption.
 */
use ON\Data\ORM\Exception\StateException;

final class RepresentationFieldBinding
{
	public function __construct(
		private string $path,
		private CollectionInterface $collection,
		private string $fieldName,
		private bool $writable = true,
		private bool $skipWhenMissing = false,
	) {
		if ($path === '') {
			throw new StateException('Representation binding path cannot be empty.');
		}

		if ($fieldName === '') {
			throw new StateException('Representation binding field name cannot be empty.');
		}
	}

	public function getPath(): string
	{
		return $this->path;
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getCollectionName(): string
	{
		return $this->collection->getName();
	}

	public function getFieldName(): string
	{
		return $this->fieldName;
	}

	public function withSkipWhenMissing(bool $skipWhenMissing): self
	{
		return new self($this->path, $this->collection, $this->fieldName, $this->writable, $skipWhenMissing);
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
