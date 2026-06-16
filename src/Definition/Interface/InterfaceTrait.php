<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

use ON\Data\Definition\Exception\InvalidDefinitionClassException;
use ON\Data\Definition\Internal\DefinitionFactory;
use ON\Data\Support\DefinitionNode;

trait InterfaceTrait
{
	protected ?InterfaceInterface $interface = null;

	/**
	 * @template T of InterfaceInterface
	 * @param class-string<T> $className
	 * @return T
	 */
	public function interface(string $className): InterfaceInterface
	{
		$interface = new $className($this);
		if (! $interface instanceof InterfaceInterface || ! $interface instanceof DefinitionNode) {
			throw new InvalidDefinitionClassException(
				sprintf('Invalid interface class "%s".', $className)
			);
		}

		$this->items['interface'] = $interface->all();
		$interfaceItems = &$this->items['interface'];
		$this->interface = DefinitionFactory::interface($this, $interfaceItems);

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
