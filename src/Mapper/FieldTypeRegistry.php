<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Mapper\Exception\FieldTypeNotFoundException;
use ON\Data\Mapper\Exception\InvalidFieldTypeException;
use ON\Data\Mapper\Field\BoolFieldType;
use ON\Data\Mapper\Field\FloatFieldType;
use ON\Data\Mapper\Field\IntFieldType;
use ON\Data\Mapper\Field\PassthroughFieldType;
use ON\Data\Mapper\Field\StringFieldType;

final class FieldTypeRegistry
{
	/**
	 * @var array<string, class-string<FieldTypeInterface>>
	 */
	private array $types = [];

	public static function createDefault(): self
	{
		return (new self())
			->register('string', StringFieldType::class)
			->register('text', PassthroughFieldType::class)
			->register('bool', BoolFieldType::class)
			->register('boolean', BoolFieldType::class)
			->register('int', IntFieldType::class)
			->register('integer', IntFieldType::class)
			->register('primary', IntFieldType::class)
			->register('smallprimary', IntFieldType::class)
			->register('float', FloatFieldType::class)
			->register('double', FloatFieldType::class);
	}

	/**
	 * @param class-string<FieldTypeInterface> $handler
	 */
	public function register(string $type, string $handler): self
	{
		if (! is_a($handler, FieldTypeInterface::class, true)) {
			throw new InvalidFieldTypeException(
				sprintf("FieldType handler '%s' must implement %s.", $handler, FieldTypeInterface::class)
			);
		}

		$this->types[$type] = $handler;
		$this->types[strtolower($type)] = $handler;

		return $this;
	}

	public function has(string $type): bool
	{
		return isset($this->types[$type]) || isset($this->types[strtolower($type)]);
	}

	/**
	 * @return class-string<FieldTypeInterface>
	 */
	public function get(string $type): string
	{
		$resolved = $this->resolve(FieldContext::named($type, $type));
		if ($resolved === null) {
			throw new FieldTypeNotFoundException(sprintf("FieldType '%s' is not registered.", $type));
		}

		return $resolved;
	}

	/**
	 * @return class-string<FieldTypeInterface>|null
	 */
	public function resolve(FieldContext $field): ?string
	{
		$type = $field->getType();

		if (is_a($type, FieldTypeInterface::class, true)) {
			/** @var class-string<FieldTypeInterface> $type */
			return $type;
		}

		if (isset($this->types[$type])) {
			return $this->types[$type];
		}

		$normalized = strtolower($type);

		return $this->types[$normalized] ?? null;
	}
}
