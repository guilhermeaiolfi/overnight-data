<?php

declare(strict_types=1);

namespace ON\Data\Query;

use LogicException;
use SplObjectStorage;

/**
 * Mutable construction scope for one source-map operation.
 *
 * It intentionally never escapes as the map consumed by expressions: seal()
 * returns the frozen SourceMap snapshot.
 */
final class SourceMapBuilder
{
	/** @var SplObjectStorage<QuerySourceInterface, QuerySourceInterface> */
	private SplObjectStorage $sources;

	private bool $sealed = false;

	public function __construct()
	{
		$this->sources = new SplObjectStorage();
	}

	public function map(QuerySourceInterface $from, QuerySourceInterface $to): void
	{
		$this->assertOpen();

		$this->sources[$from] = $to;
	}

	public function seal(): SourceMap
	{
		$this->assertOpen();
		$this->sealed = true;

		return SourceMap::fromStorage(clone $this->sources);
	}

	private function assertOpen(): void
	{
		if ($this->sealed) {
			throw new LogicException('A source map builder cannot be changed after sealing.');
		}
	}
}
