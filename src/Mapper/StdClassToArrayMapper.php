<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use stdClass;

final class StdClassToArrayMapper extends Mapper
{
	public static function canMap(
		mixed $source,
		mixed $target,
		MappingContext $context,
	): bool {
		return $source instanceof stdClass && is_array($target);
	}

	public function map(
		mixed $source,
		mixed $target,
		MappingContext $context,
	): array {
		return get_object_vars($source);
	}
}
