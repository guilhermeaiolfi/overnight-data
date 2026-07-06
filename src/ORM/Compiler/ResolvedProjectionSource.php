<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler;

/**
 * Resolved compile-time identity for one projection field shape: collection plus
 * optional concrete RecordState.
 *
 * Exists as the return type of ProjectionSourceResolverInterface so the assembler
 * can choose template vs concrete RecordFieldRef without knowing whether the
 * source came from a query or a manual projection target.
 */
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\State\RecordState;

final class ResolvedProjectionSource
{
	public function __construct(
		private CollectionInterface $collection,
		private ?RecordState $recordState = null,
	) {
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getRecordState(): ?RecordState
	{
		return $this->recordState;
	}
}
