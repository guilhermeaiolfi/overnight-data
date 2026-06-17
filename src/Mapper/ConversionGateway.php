<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Mapper\Exception\ConversionException;
use ON\Data\Mapper\Exception\FieldTypeNotFoundException;
use ON\Data\Mapper\Exception\UnsupportedConversionException;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Mapper\Representation\RepresentationInterface;
use ON\Data\Mapper\Representation\StorageRepresentation;
use ON\Data\Mapper\Representation\WireRepresentation;
use Throwable;

final class ConversionGateway
{
	/**
	 * @var array<class-string<RepresentationInterface>, RepresentationInterface>
	 */
	private array $representations = [];

	private FieldConversionCoordinator $fieldConversionCoordinator;

	private MapperManager $mappers;

	public function __construct(
		private readonly FieldTypeRegistry $fieldTypes,
		RepresentationInterface ...$representations,
	) {
		$representations = $representations !== []
			? $representations
			: [
				new PhpRepresentation(),
				new StorageRepresentation(),
				new WireRepresentation(),
			];

		foreach ($representations as $representation) {
			$this->registerRepresentation($representation);
		}

		$this->fieldConversionCoordinator = new FieldConversionCoordinator(
			$this,
			[
				new ReflectionPropertyFieldContextResolver(),
			],
		);
		$this->mappers = MapperManager::createDefault($this);
	}

	public static function createDefault(): self
	{
		return new self(FieldTypeRegistry::createDefault());
	}

	public function registerRepresentation(RepresentationInterface $representation): self
	{
		$this->representations[$representation::class] = $representation;

		return $this;
	}

	public function getFieldTypes(): FieldTypeRegistry
	{
		return $this->fieldTypes;
	}

	public function getMappers(): MapperManager
	{
		return $this->mappers;
	}

	public function getFieldConversionCoordinator(): FieldConversionCoordinator
	{
		return $this->fieldConversionCoordinator;
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
				? $this->representations[$from]->toPhp($value, $field)
				: $handler::toPhp($from, $value, $field);

			return $to === PhpRepresentation::class
				? $this->representations[$to]->fromPhp($phpValue, $field)
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
		if (! isset($this->representations[$representation])) {
			throw new UnsupportedConversionException(
				sprintf("Unknown representation '%s'.", $representation)
			);
		}
	}
}
