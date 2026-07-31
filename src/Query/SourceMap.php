<?php

declare(strict_types=1);

namespace ON\Data\Query;

use LogicException;
use ON\Data\Query\Relation\RelationRef;
use SplObjectStorage;

/**
 * Frozen anchor map for one rebind operation.
 *
 * Explicit pairs anchor a source tree. Unmapped {@see RelationRef} instances
 * beneath an anchored query or parent resolve to the same-named cached child on
 * that counterpart. Sources with no anchored ancestor stay unchanged.
 */
final class SourceMap
{
	/** @var SplObjectStorage<QuerySourceInterface, QuerySourceInterface> */
	private SplObjectStorage $sources;

	/** @param SplObjectStorage<QuerySourceInterface, QuerySourceInterface> $sources */
	private function __construct(SplObjectStorage $sources)
	{
		$this->sources = $sources;
	}

	public static function empty(): self
	{
		return new self(new SplObjectStorage());
	}

	public static function of(QuerySourceInterface $from, QuerySourceInterface $to): self
	{
		$builder = new SourceMapBuilder();
		$builder->map($from, $to);

		return $builder->seal();
	}

	public function remap(QuerySourceInterface $source): QuerySourceInterface
	{
		if ($this->sources->contains($source)) {
			return $this->sources[$source];
		}

		if ($source instanceof RelationRef) {
			return $this->resolveRelation($source);
		}

		return $source;
	}

	public function with(QuerySourceInterface $from, QuerySourceInterface $to): self
	{
		$builder = new SourceMapBuilder();
		foreach ($this->sources as $source) {
			$builder->map($source, $this->sources[$source]);
		}
		$builder->map($from, $to);

		return $builder->seal();
	}

	public function merge(self $other): self
	{
		$builder = new SourceMapBuilder();
		foreach ($this->sources as $from) {
			$builder->map($from, $this->sources[$from]);
		}
		foreach ($other->sources as $from) {
			$builder->map($from, $other->sources[$from]);
		}

		return $builder->seal();
	}

	/** @internal @param SplObjectStorage<QuerySourceInterface, QuerySourceInterface> $sources */
	public static function fromStorage(SplObjectStorage $sources): self
	{
		return new self(clone $sources);
	}

	/**
	 * Resolve an unmapped relation under the nearest anchored ancestor.
	 *
	 * Does not copy branch configuration — {@see RelationRef::rebind()} owns that.
	 */
	private function resolveRelation(RelationRef $relation): QuerySourceInterface
	{
		$parent = $relation->getParentRelation();

		if ($parent !== null) {
			$parentCounterpart = $this->remap($parent);

			if ($parentCounterpart === $parent) {
				return $relation;
			}

			return $this->relationChild($parentCounterpart, $relation->getName());
		}

		$query = $relation->getQuery();
		$queryCounterpart = $this->remap($query);

		if ($queryCounterpart === $query) {
			return $relation;
		}

		return $this->relationChild($queryCounterpart, $relation->getName());
	}

	private function relationChild(QuerySourceInterface $parent, string $name): RelationRef
	{
		if ($parent instanceof SelectQuery || $parent instanceof RelationRef) {
			return $parent->relation($name);
		}

		throw new LogicException(sprintf(
			'Cannot resolve relation "%s" under remounted source of type %s.',
			$name,
			$parent::class,
		));
	}
}
