<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Shape;

use ON\Data\Definition\Collection\CollectionInterface;
/**
 * Resolved compile-time identity for one representation field shape: collection plus
 * source path.
 *
 * Exists as the return type of RepresentationSourceResolverInterface so the assembler
 * can create structural field schemas without knowing whether the source came
 * from a query or a manual representation source.
 */
final class ResolvedRepresentationSource
{
	/** @var list<string> */
	private array $sourcePath;

	/**
	 * @param list<string> $sourcePath relation path from the schema root to the
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
