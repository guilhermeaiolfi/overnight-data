<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Result\Parser\AbstractNode;

interface LoaderInterface
{
	public function join(RelationRef $relation): QuerySourceInterface;

	public function collectFields(RelationRef $relation, LoadRuntime $runtime): void;

	public function register(RelationRef $relation, LoadRuntime $runtime): AbstractNode;

	public function load(RelationRef $relation, LoadRuntime $runtime): void;

	public function getDefaultLoadStrategy(): LoadStrategy;
}
