<?php

declare(strict_types=1);

namespace ON\Data\Definition\Display;

use Exception;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Support\DefinitionNode;

class RawDisplay extends DefinitionNode implements DisplayInterface
{
	public function __construct(
		protected mixed $parent,
		?array &$items = null,
	) {
		if ($items === null) {
			parent::__construct();

			return;
		}

		parent::__construct([]);
		$this->bind($items);
	}

	protected static function definitionDefaults(): array
	{
		return [
			'class' => static::class,
			'type' => '',
		];
	}

	public function __clone()
	{
		$this->setArray($this->all());
	}

	public function type(string $type): self
	{
		$this->set('type', $type);

		return $this;
	}

	public function getType(): string
	{
		return (string) $this->get('type');
	}

	/** @return RelationInterface|FieldInterface */
	public function end(): mixed
	{
		return $this->parent;
	}

	public function setOptions(array $options): self
	{
		$current = $this->all();
		foreach ($options as $key => $value) {
			if (! isset($current[$key])) {
				throw new Exception("There is no property {$key} in " . self::class);
			}

			$this->set((string) $key, $value);
		}

		return $this;
	}

	public function getOptions(): array
	{
		$properties = $this->all();
		$exclude = ['class', 'type'];
		foreach ($properties as $key => $value) {
			if (in_array($key, $exclude, true) || $properties[$key] == null) {
				unset($properties[$key]);
			}
		}

		return $properties;
	}
}
