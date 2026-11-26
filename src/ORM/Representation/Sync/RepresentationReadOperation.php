<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Exception\MappingException;
use function ON\Data\Mapper\map;
use WeakMap;

/** @internal Shares lazy property reads within one ORM operation. */
final readonly class RepresentationReadOperation
{
	/** @param WeakMap<ConversionGateway, WeakMap<object, array<array-key, mixed>>> $snapshots */
	public function __construct(
		private ConversionGateway $gateway,
		private WeakMap $snapshots = new WeakMap(),
	) {
	}

	public function forGateway(ConversionGateway $gateway): self
	{
		return $gateway === $this->gateway ? $this : new self($gateway, $this->snapshots);
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
