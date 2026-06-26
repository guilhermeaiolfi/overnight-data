<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationRef;

interface LoaderInterface
{
	public function join(RelationRef $relation): QuerySourceInterface;

	public function load(RelationRef $relation, LoadRuntime $runtime): void;

	public function getDefaultLoadStrategy(): LoadStrategy;

	/**
	 * @return non-empty-list<string>
	 */
	public function getParentKeyFields(RelationRef $relation): array;

	/**
	 * @return non-empty-list<string>
	 */
	public function getChildKeyFields(RelationRef $relation): array;
}
