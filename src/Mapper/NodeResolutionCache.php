<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;

final class NodeResolutionCache
{
	/**
	 * @var array<string, array<string, LeafNodeResolutionInterface|false>>
	 */
	private array $entries = [];

	public function createShapeKey(
		mixed $source,
		mixed $target,
	): string {
		return $this->describeShape($source) . ' > ' . $this->describeShape($target);
	}

	public function find(
		string $shape,
		string|int $name,
	): LeafNodeResolutionInterface|false|null {
		$key = $this->createFieldKey($name);

		return $this->entries[$shape][$key] ?? null;
	}

	public function remember(
		string $shape,
		string|int $name,
		LeafNodeResolutionInterface $resolution,
	): void {
		$this->entries[$shape][$this->createFieldKey($name)] = $resolution;
	}

	public function markDynamic(
		string $shape,
		string|int $name,
	): void {
		$this->entries[$shape][$this->createFieldKey($name)] = false;
	}

	private function createFieldKey(
		string|int $name,
	): string {
		return is_int($name)
			? 'i:' . $name
			: 's:' . $name;
	}

	private function describeShape(
		mixed $value,
	): string {
		if (is_array($value)) {
			return 'array';
		}

		if (is_object($value)) {
			return 'object:' . $value::class;
		}

		return 'scalar:' . get_debug_type($value);
	}
}
