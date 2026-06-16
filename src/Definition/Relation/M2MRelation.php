<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Internal\DefinitionFactory;

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
		DefinitionInterface $parent,
	) {
		parent::__construct($parent);
	}

	public function __clone()
	{
		parent::__clone();
		$this->restoreThroughWrapper();
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
		$this->items['through'] = M2MThrough::createDefinition();
		$throughItems = &$this->items['through'];
		$this->through = DefinitionFactory::node($this, $throughItems, M2MThrough::class, 'through');
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

	protected function afterBindDefinitionArray(): void
	{
		parent::afterBindDefinitionArray();
		unset($this->through);
		$this->restoreThroughWrapper();
	}

	private function restoreThroughWrapper(): void
	{
		if (! is_array($this->get('through'))) {
			return;
		}

		$throughItems = &$this->items['through'];
		$this->through = DefinitionFactory::node($this, $throughItems, M2MThrough::class, 'through');
	}
}
