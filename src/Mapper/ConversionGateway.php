<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Mapper\Exception\ConversionException;
use ON\Data\Mapper\Exception\FieldTypeNotFoundException;
use ON\Data\Mapper\Exception\UnsupportedConversionException;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Mapper\Representation\RepresentationInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use Throwable;

final class ConversionGateway
{
	private MapperManager $mapperManager;

	/**
	 * @var array<
	 *     string,
	 *     array{
	 *         sourceConverter: class-string<FieldTypeInterface>|class-string<FieldTypeCodecInterface>|null,
	 *         destinationConverter: class-string<FieldTypeInterface>|class-string<FieldTypeCodecInterface>|null
	 *     }
	 * >
	 */
	private array $routes = [];

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

	public function clearRoutes(): void
	{
		$this->routes = [];
	}

	/**
	 * @param class-string<RepresentationInterface> $from
	 * @param class-string<RepresentationInterface> $to
	 */
	public function to(
		string $from,
		mixed $value,
		string $to,
		LeafNodeResolutionInterface $field,
	): mixed {
		if ($value === null) {
			return null;
		}

		$this->requireRepresentation($from);
		$this->requireRepresentation($to);

		if ($from === $to) {
			return $value;
		}

		$route = $this->resolveRoute($from, $to, $field);

		try {
			$phpValue = $route['sourceConverter'] === null
				? $value
				: $route['sourceConverter']::toPhp($value, $field);

			return $route['destinationConverter'] === null
				? $phpValue
				: $route['destinationConverter']::fromPhp($phpValue, $field);
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
	 * @param class-string<RepresentationInterface> $from
	 * @param class-string<RepresentationInterface> $to
	 *
	 * @return array{
	 *     sourceConverter: class-string<FieldTypeInterface>|class-string<FieldTypeCodecInterface>|null,
	 *     destinationConverter: class-string<FieldTypeInterface>|class-string<FieldTypeCodecInterface>|null
	 * }
	 */
	private function resolveRoute(
		string $from,
		string $to,
		LeafNodeResolutionInterface $field,
	): array {
		$routeKey = $this->routeKey($from, $to, $field);
		if (isset($this->routes[$routeKey])) {
			return $this->routes[$routeKey];
		}

		$fieldType = $this->mapperManager->resolveFieldType($field);
		if ($fieldType === null) {
			throw new FieldTypeNotFoundException(
				sprintf("Field '%s' uses unknown FieldType '%s'.", $field->getName(), $field->getType())
			);
		}

		return $this->routes[$routeKey] = [
			'sourceConverter' => $from === PhpRepresentation::class
				? null
				: $this->resolveConverter($fieldType, $from),
			'destinationConverter' => $to === PhpRepresentation::class
				? null
				: $this->resolveConverter($fieldType, $to),
		];
	}

	/**
	 * @param class-string<RepresentationInterface> $from
	 * @param class-string<RepresentationInterface> $to
	 */
	private function routeKey(
		string $from,
		string $to,
		LeafNodeResolutionInterface $field,
	): string {
		return $from . '|' . $to . '|' . ($field->getType() ?? '<null>');
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
