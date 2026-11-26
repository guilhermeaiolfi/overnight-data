<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Query;

use ON\Data\Query\Result\WritablePreparation;
use ON\Data\Schema\RepresentationSchema;

/** The schema prepared for one writable fetch. */
final readonly class QueryRepresentationPlan implements WritablePreparation
{
	public function __construct(private RepresentationSchema $schema)
	{
	}

	public function getFetchSchema(): RepresentationSchema
	{
		return $this->schema;
	}
}
