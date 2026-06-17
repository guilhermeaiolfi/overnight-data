<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Mapper\Exception\ConversionException;
use ON\Data\Mapper\Exception\FieldTypeNotFoundException;
use ON\Data\Mapper\Exception\UnsupportedConversionException;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Mapper\Representation\RepresentationInterface;
use Throwable;

final class ConversionGateway
{
	private MapperManager $mappers;

	public function __construct(
		private readonly FieldTypeRegistry $fieldTypes,
	) {
		$this->mappers = MapperManager::createDefault($this);
	}

	public static function createDefault(): self
	{
		return new self(FieldTypeRegistry::createDefault());
	}

	public function getFieldTypes(): FieldTypeRegistry
	{
		return $this->fieldTypes;
	}

	public function getMappers(): MapperManager
	{
		return $this->mappers;
	}

	/**
	 * @param class-string<RepresentationInterface> $from
	 * @param class-string<RepresentationInterface> $to
	 */
	public function to(
		string $from,
		mixed $value,
		string $to,
		FieldContext $field,
	): mixed {
		if ($value === null) {
			return null;
		}

		$this->requireRepresentation($from);
		$this->requireRepresentation($to);

		if ($from === $to) {
			return $value;
		}

		$handler = $this->fieldTypes->resolve($field);
		if ($handler === null) {
			throw new FieldTypeNotFoundException(
				sprintf("Field '%s' uses unknown FieldType '%s'.", $field->getName(), $field->getType())
			);
		}

		try {
			$phpValue = $from === PhpRepresentation::class
				? $value
				: $handler::toPhp($from, $value, $field);

			return $to === PhpRepresentation::class
				? $phpValue
				: $handler::fromPhp($to, $phpValue, $field);
		} catch (FieldTypeNotFoundException|UnsupportedConversionException $exception) {
			throw $exception;
		} catch (Throwable $exception) {
			throw ConversionException::forField($field, $from, $to, $exception);
		}
	}

	/**
	 * @param class-string<RepresentationInterface> $representation
	 */
	private function requireRepresentation(string $representation): void
	{
		if (! is_a($representation, RepresentationInterface::class, true)) {
			throw new UnsupportedConversionException(
				sprintf("Unknown representation '%s'.", $representation)
			);
		}
	}
}
