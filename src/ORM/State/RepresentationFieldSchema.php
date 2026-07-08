<?php

declare(strict_types=1);

namespace ON\Data\ORM\State;

use ON\Data\Definition\Collection\CollectionInterface;
/**
 * One structural scalar representation path with writability and optional
 * skip-when-missing adoption behavior.
 *
 * Exists as the leaf node of RepresentationSchema used by scalar sync and flat
 * projection adoption.
 */
use ON\Data\ORM\Exception\StateException;

final class RepresentationFieldSchema
{
	/** @var list<string> */
	private array $sourcePath;

	/**
	 * @param list<string> $sourcePath relation path from the schema root to the
	 *                                  record that owns this field ([] for root)
	 */
	public function __construct(
		private string $path,
		private CollectionInterface $collection,
		private string $fieldName,
		private bool $writable = true,
		private bool $skipWhenMissing = false,
		array $sourcePath = [],
	) {
		if ($path === '') {
			throw new StateException('Representation schema path cannot be empty.');
		}

		if ($fieldName === '') {
			throw new StateException('Representation schema field name cannot be empty.');
		}

		$this->sourcePath = array_values($sourcePath);
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

	/**
	 * @return list<string>
	 */
	public function getSourcePath(): array
	{
		return $this->sourcePath;
	}

	public function getSourcePathKey(): string
	{
		return self::sourcePathKey($this->sourcePath);
	}

	/**
	 * @param list<string> $sourcePath
	 */
	public static function sourcePathKey(array $sourcePath): string
	{
		return implode('.', $sourcePath);
	}

	public function isRootSource(): bool
	{
		return $this->sourcePath === [];
	}

	public function withSkipWhenMissing(bool $skipWhenMissing): self
	{
		return new self($this->path, $this->collection, $this->fieldName, $this->writable, $skipWhenMissing, $this->sourcePath);
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
