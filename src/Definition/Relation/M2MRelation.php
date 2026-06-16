<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

class M2MRelation extends AbstractRelation
{
	public M2MThrough $through;
	// Collection type that will contain loaded entities.
	protected string $collection_factory;

	public function getCardinality(): string
	{
		return 'many';
	}

	public function isJunction(): bool
	{
		return true;
	}

	public function through(string $collection): M2MThrough
	{
		$this->through = new M2MThrough($this);
		$this->through->collection($collection);

		return $this->through;
	}

	public function collectionFactory(string $factory): self
	{
		$this->collection_factory = $factory;

		return $this;
	}

	public function getCollectionFactory(): string
	{
		return $this->collection_factory;
	}
}
