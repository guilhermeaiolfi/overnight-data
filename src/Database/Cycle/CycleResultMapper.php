<?php

declare(strict_types=1);

namespace ON\Data\Database\Cycle;

use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Mapper\Representation\StorageRepresentation;
use ON\Data\Mapper\Resolution\LeafNodeResolution;

/**
 * @internal
 */
final class CycleResultMapper
{
	public function __construct(
		private readonly ConversionGateway $gateway,
	) {
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public function map(array $row, CycleQueryPlan $plan): array
	{
		$mapped = [];

		foreach ($plan->columns() as $column) {
			$value = $row[$column->backendName()] ?? null;
			$field = $column->field();

			if ($field !== null) {
				$value = $this->gateway->to(
					StorageRepresentation::class,
					$value,
					PhpRepresentation::class,
					LeafNodeResolution::fromField($field),
				);
			}

			$mapped[$column->logicalName()] = $value;
		}

		return $mapped;
	}
}
