<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\Relation\Loader\AbstractLoader;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Result\Parser\AbstractNode;

final class RequiredCtorLoader extends AbstractLoader
{
	private string $value;

	public function __construct(string $value)
	{
		$this->value = $value;
	}

	protected function initNode(RelationRef $relation, LoadRuntime $runtime): AbstractNode
	{
		throw RelationLoaderException::loadingNotImplemented($relation);
	}
}
