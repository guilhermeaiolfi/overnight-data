<?php

declare(strict_types=1);

namespace ON\Data\Definition\Display;

use ON\Data\Definition\Exception\InvalidDefinitionClassException;
use ON\Data\Definition\Internal\DefinitionFactory;

trait DisplayTrait
{
	protected ?DisplayInterface $display = null;

	/**
	 * @template T of DisplayInterface
	 * @param class-string<T> $type
	 * @return T
	 */
	public function display(string $type = RawDisplay::class): DisplayInterface
	{
		if (isset($this->items['display']) && is_array($this->items['display'])) {
			$display = $this->getDisplay();
			if ($display::class !== $type) {
				throw new InvalidDefinitionClassException(sprintf('Invalid display class "%s".', $type));
			}

			return $display;
		}

		$this->items['display'] = [];
		$displayItems = &$this->items['display'];
		$this->display = DefinitionFactory::createDisplay($this, 'display', $displayItems, $type);

		return $this->display;
	}

	public function getDisplay(): DisplayInterface
	{
		if ($this->display !== null) {
			return $this->display;
		}

		if (! isset($this->items['display']) || ! is_array($this->items['display'])) {
			return $this->display(RawDisplay::class);
		}

		$displayItems = &$this->items['display'];
		$this->display = DefinitionFactory::restoreDisplay($this, 'display', $displayItems);

		return $this->display;
	}
};
