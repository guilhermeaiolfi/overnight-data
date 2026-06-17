<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\Writer\WriterInterface;

final class NeverWriter implements WriterInterface
{
	public function __construct()
	{
		ComponentTestState::recordConstruction(self::class);
	}

	public static function canWrite(
		mixed $target,
		MappingContext $context,
	): bool {
		ComponentTestState::recordSelection(self::class);

		return false;
	}

	public function prepare(
		mixed $target,
		MappingContext $context,
	): mixed {
		ComponentTestState::recordRuntime(self::class, $context->getPath());

		return $target;
	}

	public function write(
		mixed $target,
		string|int $name,
		mixed $value,
		MappingContext $context,
		mixed $walkerArguments = null,
	): mixed {
		return $target;
	}

	public function finish(
		mixed $target,
		MappingContext $context,
	): mixed {
		return $target;
	}
}
