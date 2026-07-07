<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler;

/**
 * Resolved compile-time identity for one projection field shape: collection plus
 * source path.
 *
 * Exists as the return type of ProjectionSourceResolverInterface so the assembler
 * can create structural field bindings without knowing whether the source came
 * from a query or a manual projection target.
 */
use ON\Data\Definition\Collection\CollectionInterface;

final class ResolvedProjectionSource
{
	/** @var list<string> */
	private array $sourcePath;

	/**
	 * @param list<string> $sourcePath relation path from the binding root to the
	 *                                  record that owns the resolved field
	 */
	public function __construct(
		private CollectionInterface $collection,
		array $sourcePath = [],
	) {
		$this->sourcePath = array_values($sourcePath);
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	/**
	 * @return list<string>
	 */
	public function getSourcePath(): array
	{
		return $this->sourcePath;
	}
}
