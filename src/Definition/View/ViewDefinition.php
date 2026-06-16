<?php

declare(strict_types=1);

namespace ON\Data\Definition\View;

use InvalidArgumentException;
use ON\Data\Definition\AbstractDefinition;
use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Exception\DefinitionNotFoundException;
use ON\Data\Definition\Field\FieldInterface;

class ViewDefinition extends AbstractDefinition implements ViewDefinitionInterface
{
	protected static function definitionDefaults(): array
	{
		return [
			'class' => static::class,
			'source' => null,
			'fields' => [],
			'relations' => [],
			'metadata' => [],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function defaultDefinition(string $name): array
	{
		return static::createDefinition();
	}

	public function source(string|DefinitionInterface $source): self
	{
		$sourceName = $source instanceof DefinitionInterface ? $source->getName() : trim($source);
		if ($sourceName === '') {
			throw new InvalidArgumentException('View source name cannot be empty.');
		}

		$this->set('source', $sourceName);

		return $this;
	}

	public function getSourceName(): ?string
	{
		$value = $this->get('source');

		return is_string($value) && $value !== '' ? $value : null;
	}

	public function getSource(): ?DefinitionInterface
	{
		$sourceName = $this->getSourceName();
		if ($sourceName === null) {
			return null;
		}

		$source = $this->getRegistry()->getDefinition($sourceName);
		if ($source === null) {
			throw new DefinitionNotFoundException(sprintf("View source '%s' is not registered.", $sourceName));
		}

		return $source;
	}

	public function hasSource(): bool
	{
		return $this->getSourceName() !== null;
	}

	/**
	 * @return class-string<FieldInterface>
	 */
	protected function defaultFieldClass(): string
	{
		return ViewField::class;
	}
}
