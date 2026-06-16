<?php

declare(strict_types=1);

namespace ON\Data\Definition\Interface;

use ON\Data\Definition\Exception\InvalidDefinitionClassException;
use ON\Data\Definition\Internal\DefinitionFactory;

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
		if (isset($this->items['interface']) && is_array($this->items['interface'])) {
			$interface = $this->getInterface();
			if ($interface::class !== $className) {
				throw new InvalidDefinitionClassException(sprintf('Invalid interface class "%s".', $className));
			}

			return $interface;
		}

		$this->items['interface'] = [];
		$interfaceItems = &$this->items['interface'];
		$this->interface = DefinitionFactory::createInterface($this, 'interface', $interfaceItems, $className);

		return $this->interface;
	}

	public function getInterface(): InterfaceInterface
	{
		if ($this->interface !== null) {
			return $this->interface;
		}

		if (! isset($this->items['interface']) || ! is_array($this->items['interface'])) {
			throw new InvalidDefinitionClassException('Interface definition is not configured.');
		}

		$interfaceItems = &$this->items['interface'];
		$this->interface = DefinitionFactory::restoreInterface($this, 'interface', $interfaceItems);

		return $this->interface;
	}
};
