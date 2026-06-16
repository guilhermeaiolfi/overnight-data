<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

use ON\Data\Definition\Internal\DefinitionFactory;

class M2MRelation extends AbstractRelation
{
	public ?M2MThrough $through = null;

	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'collection_factory' => '',
			'through' => null,
		]);
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
		if (! isset($this->items['through']) || ! is_array($this->items['through'])) {
			$this->items['through'] = [];
			$throughItems = &$this->items['through'];
			$this->through = DefinitionFactory::create(
				$this,
				'through',
				$throughItems,
				M2MThrough::class,
				M2MThrough::class,
				'through',
			);
		}

		$this->through ??= $this->restoreThrough();
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

	protected function initializeRuntimeState(): void
	{
		parent::initializeRuntimeState();
		$this->through = $this->restoreThrough();
	}

	private function restoreThrough(): ?M2MThrough
	{
		if (! isset($this->items['through']) || ! is_array($this->items['through'])) {
			return null;
		}

		$throughItems = &$this->items['through'];

		/** @var M2MThrough $through */
		$through = DefinitionFactory::restore(
			$this,
			'through',
			$throughItems,
			M2MThrough::class,
			'through',
		);

		return $through;
	}
}
