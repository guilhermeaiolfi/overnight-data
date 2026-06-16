<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use stdClass;

final class ArrayToStdClassMapper extends Mapper
{
	public static function canMap(
		mixed $source,
		mixed $target,
		MappingContext $context,
	): bool {
		return is_array($source)
			&& ($target === stdClass::class || $target instanceof stdClass);
	}

	public function map(
		mixed $source,
		mixed $target,
		MappingContext $context,
	): stdClass {
		$result = $target instanceof stdClass ? clone $target : new stdClass();

		foreach ($source as $key => $value) {
			$result->{(string) $key} = $value;
		}

		return $result;
	}
}
