<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

trait InterfaceTrait
{
	protected ?InterfaceInterface $interface = null;

	/**
	 * @template T
	 * @param class-string<T> $className
	 * @return T
	 */
	public function interface(string $className): InterfaceInterface
	{
		$this->interface = new $className($this);

		return $this->interface;
	}

	public function getInterface(): InterfaceInterface
	{
		return $this->interface;
	}
};
