<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

use Exception;
use ON\Data\Support\DefinitionNode;

abstract class AbstractInterface extends DefinitionNode implements InterfaceInterface
{
	protected static function definitionDefaults(): array
	{
		return [
			'class' => static::class,
		];
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
		foreach ($properties as $key => $value) {
			if ($key === 'class' || $properties[$key] == null) {
				unset($properties[$key]);
			}
		}

		return $properties;
	}

	public function end(): DefinitionNode
	{
		$owner = $this->owner();
		if (! $owner instanceof DefinitionNode) {
			throw new Exception('Interface owner is invalid.');
		}

		return $owner;
	}
}
