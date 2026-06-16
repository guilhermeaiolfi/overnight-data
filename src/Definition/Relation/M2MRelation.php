<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

use ON\Data\Definition\Collection\CollectionInterface;

class M2MRelation extends AbstractRelation
{
	public M2MThrough $through;

	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'collection_factory' => '',
			'through' => null,
		]);
	}

	public function __construct(
		public CollectionInterface $parent,
		?array &$items = null,
	) {
		parent::__construct($parent, $items);

		if (is_array($this->get('through'))) {
			$throughItems = &$this->items['through'];
			$this->through = new M2MThrough($this, $throughItems);
		}
	}

	public function __clone()
	{
		parent::__clone();
		if (is_array($this->get('through'))) {
			$throughItems = &$this->items['through'];
			$this->through = new M2MThrough($this, $throughItems);
		}
	}

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
		$this->set('through', []);
		$throughItems = &$this->items['through'];
		$this->through = new M2MThrough($this, $throughItems);
		$this->through->collection($collection);

		return $this->through;
	}

	public function collectionFactory(string $factory): self
	{
		$this->set('collection_factory', $factory);

		return $this;
	}

	public function getCollectionFactory(): string
	{
		return (string) $this->get('collection_factory');
	}
}
