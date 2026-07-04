<?php

declare(strict_types=1);

namespace Tests\ON\Data\Support\Relation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Persistence\CommandInterface;

final class TestCommand implements CommandInterface
{
	public function __construct(
		private CollectionInterface $collection,
	) {
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}
}
