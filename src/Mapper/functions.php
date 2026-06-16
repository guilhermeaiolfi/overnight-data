<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

function map(
	mixed $source,
	?string $from = null,
	?ConversionGateway $gateway = null,
): MapBuilder {
	return new MapBuilder(
		source: $source,
		gateway: $gateway ?? Mapping::getDefaultGateway(),
		sourceRepresentation: $from,
	);
}
