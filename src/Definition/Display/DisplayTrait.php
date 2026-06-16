<?php

declare(strict_types=1);

namespace ON\Data\Definition\Display;

use ON\Data\Definition\Exception\InvalidDefinitionClassException;
use ON\Data\Definition\Internal\DefinitionFactory;
use ON\Data\Support\DefinitionNode;

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
		$display = new $type($this);
		if (! $display instanceof DisplayInterface || ! $display instanceof DefinitionNode) {
			throw new InvalidDefinitionClassException(
				sprintf('Invalid display class "%s".', $type)
			);
		}

		$this->items['display'] = $display->all();
		$displayItems = &$this->items['display'];
		$this->display = DefinitionFactory::display($this, $displayItems);

		return $this->display;
	}

	public function getDisplay(): DisplayInterface
	{
		if ($this->display !== null) {
			return $this->display;
		}

		$displayItems = &$this->items['display'];
		$this->display = DefinitionFactory::display($this, $displayItems);

		return $this->display;
	}
};
