<?php

declare(strict_types=1);

namespace ON\Data\Query\Load;

use ON\Data\ORM\Representation\Schema\RepresentationSchema;

/**
 * Place + fetch snapshot compiled before a fetch runs (proposal 0003 Phase 1).
 *
 * {@see RepresentationSchema} is the place graph; {@see LoadGraph} is the fetch graph.
 * Output assembly still uses today's processor — this plan makes both graphs
 * available before {@see LoadRuntime} runs.
 */
final class FetchPlan
{
	public function __construct(
		private readonly RepresentationSchema $schema,
		private readonly LoadGraph $loadGraph,
	) {
	}

	public function getSchema(): RepresentationSchema
	{
		return $this->schema;
	}

	public function getLoadGraph(): LoadGraph
	{
		return $this->loadGraph;
	}
}
