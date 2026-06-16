<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

use ON\Data\Definition\Internal\DefinitionFactory;

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
		$items = ['class' => $className];
		$this->set('interface', $items);
		$interfaceItems = &$this->items['interface'];
		$this->interface = new $className($this, $interfaceItems);

		return $this->interface;
	}

	public function getInterface(): InterfaceInterface
	{
		if ($this->interface !== null) {
			return $this->interface;
		}

		$interfaceItems = &$this->items['interface'];
		$this->interface = DefinitionFactory::interface($this, $interfaceItems);

		return $this->interface;
	}
};
