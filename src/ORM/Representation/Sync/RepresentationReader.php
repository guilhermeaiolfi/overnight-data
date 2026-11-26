<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Mapping;

/** Configures the mapper used for representation reads. */
final readonly class RepresentationReader
{
	public function __construct(private ?ConversionGateway $gateway = null)
	{
	}

	/** @internal Share one cache when an operation uses several configured readers. */
	public function forOperation(?RepresentationReadOperation $operation = null): RepresentationReadOperation
	{
		$gateway = $this->gateway ?? Mapping::getDefaultGateway();

		return $operation === null ? new RepresentationReadOperation($gateway) : $operation->forGateway($gateway);
	}
}
