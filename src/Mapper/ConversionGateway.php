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
	private MapperManager $mapperManager;

	public function __construct()
	{
		$this->mapperManager = new MapperManager($this);
	}

	public static function createDefault(): self
	{
		$gateway = new self();
		$gateway->mapperManager = MapperManager::createDefault($gateway);

		return $gateway;
	}

	public function getMapperManager(): MapperManager
	{
		return $this->mapperManager;
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

		$fieldType = $this->mapperManager->resolveFieldType($field);
		if ($fieldType === null) {
			throw new FieldTypeNotFoundException(
				sprintf("Field '%s' uses unknown FieldType '%s'.", $field->getName(), $field->getType())
			);
		}

		try {
			$phpValue = $from === PhpRepresentation::class
				? $value
				: $this->resolveConverter($fieldType, $from)::toPhp($value, $field);

			return $to === PhpRepresentation::class
				? $phpValue
				: $this->resolveConverter($fieldType, $to)::fromPhp($phpValue, $field);
		} catch (FieldTypeNotFoundException|UnsupportedConversionException $exception) {
			throw $exception;
		} catch (Throwable $exception) {
			throw ConversionException::forField($field, $from, $to, $exception);
		}
	}

	/**
	 * @param class-string<FieldTypeInterface> $fieldType
	 * @param class-string<RepresentationInterface> $representation
	 *
	 * @return class-string<FieldTypeInterface>|class-string<FieldTypeCodecInterface>
	 */
	private function resolveConverter(string $fieldType, string $representation): string
	{
		return $this->mapperManager->resolveFieldTypeCodec($fieldType, $representation) ?? $fieldType;
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
