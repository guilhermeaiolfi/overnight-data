<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Exception\MappingException;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Mapping;
use WeakMap;

/** Shares shallow-mapped properties across DTOs during one ORM operation. */
final class RepresentationReader
{
	private ConversionGateway $gateway;

	/** @var WeakMap<ConversionGateway, WeakMap<object, array<array-key, mixed>>> */
	private readonly WeakMap $snapshots;

	public function __construct(?ConversionGateway $gateway = null)
	{
		$this->gateway = $gateway ?? Mapping::getDefaultGateway();
		$this->snapshots = new WeakMap();
	}

	/** @internal Share the operation cache with a synchronizer using its own gateway. */
	public function forGateway(?ConversionGateway $gateway): self
	{
		$gateway ??= Mapping::getDefaultGateway();
		if ($gateway === $this->gateway) {
			return $this;
		}

		$reader = clone $this;
		$reader->gateway = $gateway;

		return $reader;
	}

	public function withObject(object $source): ObjectRepresentationReader
	{
		return new ObjectRepresentationReader($source, $this->propertiesOf(...));
	}

	/** @return array<array-key, mixed> */
	private function propertiesOf(object $source): array
	{
		$properties = $this->snapshots[$this->gateway] ??= new WeakMap();
		if (isset($properties[$source])) {
			return $properties[$source];
		}

		$result = map($source, gateway: $this->gateway)->shallow()->to([]);
		if (! is_array($result)) {
			throw new MappingException('Reading representation properties requires an array mapping result.');
		}

		return $properties[$source] = $result;
	}
}
