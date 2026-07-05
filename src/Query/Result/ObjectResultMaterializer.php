<?php

declare(strict_types=1);

namespace ON\Data\Query\Result;

use ON\Data\Query\Exception\ObjectExportException;
use stdClass;

final class ObjectResultMaterializer
{
	public function materialize(array $data, string $class): object
	{
		return $this->convertAssociativeArray($data, $class);
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 *
	 * @return list<object>
	 */
	public function materializeAll(array $rows, string $class): array
	{
		$materialized = [];

		foreach ($rows as $row) {
			$materialized[] = $this->materialize($row, $class);
		}

		return $materialized;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function convertAssociativeArray(array $data, string $class): object
	{
		$object = $this->createInstance($class);

		foreach ($data as $key => $value) {
			$object->{$key} = $this->convertValue($value, $class);
		}

		return $object;
	}

	private function convertValue(mixed $value, string $class): mixed
	{
		if ($value === null || is_scalar($value)) {
			return $value;
		}

		if (is_object($value)) {
			return $value;
		}

		if (! is_array($value)) {
			return $value;
		}

		if ($this->isListArray($value)) {
			$converted = [];

			foreach ($value as $item) {
				$converted[] = $this->convertValue($item, $class);
			}

			return $converted;
		}

		return $this->convertAssociativeArray($value, $class);
	}

	/**
	 * @param array<mixed> $value
	 */
	private function isListArray(array $value): bool
	{
		return array_is_list($value);
	}

	private function createInstance(string $class): object
	{
		if ($class !== stdClass::class) {
			throw ObjectExportException::unsupportedClass($class);
		}

		return new stdClass();
	}
}
