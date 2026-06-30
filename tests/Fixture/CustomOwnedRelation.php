<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Display\DisplayTrait;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Interface\InterfaceTrait;
use ON\Data\Definition\Internal\DefinitionFactory;
use ON\Data\Definition\MetadataTrait;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Query\Relation\Loader\HasManyLoader;
use ON\Data\Query\Relation\Loader\LoaderInterface;
use ON\Data\Support\DefinitionNode;

final class CustomOwnedRelation extends DefinitionNode implements RelationInterface
{
	use DisplayTrait;
	use InterfaceTrait;
	use MetadataTrait;

	private ?CustomRelationOptions $options = null;

	protected static function definitionDefaults(): array
	{
		return [
			'class' => static::class,
			'collectionName' => '',
			'nullable' => false,
			'cascade' => true,
			'load' => 'lazy',
			'inner_keys' => [],
			'outer_keys' => [],
			'where' => [],
			'orderBy' => [],
			'loader' => HasManyLoader::class,
			'metadata' => [],
			'options' => null,
		];
	}

	public function getParent(): DefinitionInterface
	{
		$owner = $this->owner();

		return $owner instanceof DefinitionInterface
			? $owner
			: throw new LogicException("Relation '{$this->getName()}' parent is invalid.");
	}

	public function collection(string $collectionName): self
	{
		$this->set('collectionName', $collectionName);

		return $this;
	}

	public function getCollectionName(): string
	{
		return (string) $this->get('collectionName');
	}

	public function getCollection(): CollectionInterface
	{
		$collection = $this->getParent()->getRegistry()->getCollection($this->getCollectionName());
		if ($collection === null) {
			throw new LogicException("Target collection {$this->getCollectionName()} is not registered.");
		}

		return $collection;
	}

	public function nullable(bool $nullable): self
	{
		$this->set('nullable', $nullable);

		return $this;
	}

	public function isNullable(): bool
	{
		return (bool) $this->get('nullable');
	}

	public function cascade(bool $cascade): self
	{
		$this->set('cascade', $cascade);

		return $this;
	}

	public function isCascade(): bool
	{
		return (bool) $this->get('cascade');
	}

	public function load(string $load): self
	{
		$this->set('load', $load);

		return $this;
	}

	public function getLoadStrategy(): string
	{
		return (string) $this->get('load');
	}

	public function innerKey(string|array $fieldName): self
	{
		$this->set('inner_keys', is_array($fieldName) ? array_values($fieldName) : [$fieldName]);

		return $this;
	}

	public function getInnerKey(): string|array
	{
		$keys = $this->getInnerKeys();

		return count($keys) === 1 ? $keys[0] : $keys;
	}

	public function getInnerKeys(): array
	{
		$keys = $this->get('inner_keys');

		return is_array($keys) ? $keys : [];
	}

	public function getInnerField(): FieldInterface
	{
		return $this->getParent()->getField((string) $this->getInnerKey()) ?? throw new LogicException('Inner field is not defined.');
	}

	public function outerKey(string|array $fieldName): self
	{
		$this->set('outer_keys', is_array($fieldName) ? array_values($fieldName) : [$fieldName]);

		return $this;
	}

	public function getOuterKey(): string|array
	{
		$keys = $this->getOuterKeys();

		return count($keys) === 1 ? $keys[0] : $keys;
	}

	public function getOuterKeys(): array
	{
		$keys = $this->get('outer_keys');

		return is_array($keys) ? $keys : [];
	}

	public function getOuterField(): FieldInterface
	{
		return $this->getCollection()->getField((string) $this->getOuterKey()) ?? throw new LogicException('Outer field is not defined.');
	}

	public function loader(string $loader): self
	{
		if (! is_a($loader, LoaderInterface::class, true)) {
			throw new LogicException(sprintf('Relation "%s" loader must implement %s.', $this->getName(), LoaderInterface::class));
		}

		$this->set('loader', $loader);

		return $this;
	}

	public function getLoader(): string
	{
		$loader = $this->get('loader');

		if (! is_string($loader) || $loader === '') {
			throw new LogicException(sprintf('Relation "%s" does not define a loader.', $this->getName()));
		}

		if (! is_a($loader, LoaderInterface::class, true)) {
			throw new LogicException(sprintf('Relation "%s" loader must implement %s.', $this->getName(), LoaderInterface::class));
		}

		return $loader;
	}

	public function where(array $where): self
	{
		$this->set('where', $where);

		return $this;
	}

	public function getWhere(): array
	{
		$where = $this->get('where');

		return is_array($where) ? $where : [];
	}

	public function orderBy(array $orderBy): self
	{
		$this->set('orderBy', $orderBy);

		return $this;
	}

	public function getOrderBy(): array
	{
		$orderBy = $this->get('orderBy');

		return is_array($orderBy) ? $orderBy : [];
	}

	public function getCardinality(): string
	{
		return 'many';
	}

	public function isJunction(): bool
	{
		return false;
	}

	public function options(): CustomRelationOptions
	{
		if ($this->options !== null) {
			return $this->options;
		}

		if (! isset($this->items['options']) || ! is_array($this->items['options'])) {
			$this->items['options'] = [];
			$optionItems = &$this->items['options'];
			$this->options = DefinitionFactory::create(
				$this,
				'options',
				$optionItems,
				CustomRelationOptions::class,
				CustomRelationOptions::class,
				'relation options',
			);

			return $this->options;
		}

		$optionItems = &$this->items['options'];

		/** @var CustomRelationOptions $options */
		$options = DefinitionFactory::restore(
			$this,
			'options',
			$optionItems,
			CustomRelationOptions::class,
			'relation options',
		);

		return $this->options = $options;
	}

	public function end(): DefinitionInterface
	{
		return $this->getParent();
	}

	protected function initializeRuntimeState(): void
	{
		$this->display = null;
		$this->interface = null;
		$this->metadataMap = null;
		$this->options = null;

		if (isset($this->items['options']) && is_array($this->items['options'])) {
			$this->options();
		}
	}
}
