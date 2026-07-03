<?php

declare(strict_types=1);

namespace ON\Data\ORM\State;

use ON\Data\ORM\Exception\StateException;

final class RepresentationBinding
{
	/** @var array<string, RepresentationFieldBinding> */
	private array $bindings = [];

	public function add(RepresentationFieldBinding $binding): void
	{
		$path = $binding->getPath();
		if (array_key_exists($path, $this->bindings)) {
			throw new StateException(sprintf("Representation binding already contains path '%s'.", $path));
		}

		$this->bindings[$path] = $binding;
	}

	public function has(string $path): bool
	{
		return array_key_exists($path, $this->bindings);
	}

	public function get(string $path): RepresentationFieldBinding
	{
		if (! array_key_exists($path, $this->bindings)) {
			throw new StateException(sprintf("Representation binding does not contain path '%s'.", $path));
		}

		return $this->bindings[$path];
	}

	/**
	 * @return list<RepresentationFieldBinding>
	 */
	public function getAll(): array
	{
		return array_values($this->bindings);
	}

	/**
	 * @return list<RepresentationFieldBinding>
	 */
	public function getWritableBindings(): array
	{
		return array_values(array_filter(
			$this->bindings,
			static fn (RepresentationFieldBinding $binding): bool => $binding->isWritable()
		));
	}

	/**
	 * @return list<RepresentationFieldBinding>
	 */
	public function getReadOnlyBindings(): array
	{
		return array_values(array_filter(
			$this->bindings,
			static fn (RepresentationFieldBinding $binding): bool => $binding->isReadOnly()
		));
	}
}
