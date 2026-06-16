<?php

declare(strict_types=1);

namespace ON\Data\Definition\Display;

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
		$this->display = new $type($this);

		return $this->display;
	}

	public function getDisplay(): DisplayInterface
	{
		return $this->display;
	}
};
