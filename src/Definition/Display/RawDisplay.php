<?php

declare(strict_types=1);

namespace ON\Data\Definition\Display;

use Exception;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Relation\RelationInterface;

class RawDisplay implements DisplayInterface
{
	protected string $type;
	protected ?array $options = null;

	public function __construct(
		protected mixed $parent
	) {

	}

	public function type(string $type): self
	{
		$this->type = $type;

		return $this;
	}

	public function getType(): string
	{
		return $this->type;
	}

	/** @return RelationInterface|FieldInterface */
	public function end(): mixed
	{
		return $this->parent;
	}

	public function setOptions(array $options): self
	{
		foreach ($options as $key => $value) {
			if (! isset($this->$key)) {
				throw new Exception("There is no property {$key} in " . self::class);
			}
			$this->$key = $value;
		}

		return $this;
	}

	public function getOptions(): array
	{
		$properties = get_object_vars($this);

		$exclude = ["type", "parent"];
		foreach ($properties as $key => $value) {
			if (in_array($key, $exclude) || $properties[$key] == null) {
				unset($properties[$key]);
			}
		}

		return $properties;
	}
}
