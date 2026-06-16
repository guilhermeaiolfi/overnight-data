<?php

declare(strict_types=1);

namespace ON\Data\Definition\Display;

use ON\Data\Definition\Internal\DefinitionFactory;

trait DisplayTrait
{
	protected ?DisplayInterface $display = null;

	/**
	 * @template T
	 * @param class-string<T> $type
	 * @return T
	 */
	public function display(string $type = RawDisplay::class): DisplayInterface
	{
		$items = ['class' => $type];
		$this->set('display', $items);
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
