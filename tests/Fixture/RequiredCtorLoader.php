<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Query\Relation\Loader\AbstractLoader;

final class RequiredCtorLoader extends AbstractLoader
{
	private string $value;

	public function __construct(string $value)
	{
		$this->value = $value;
	}
}
