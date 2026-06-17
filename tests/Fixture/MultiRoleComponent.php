<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use Closure;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\Walker\WalkerInterface;
use ON\Data\Mapper\Writer\WriterInterface;

final class MultiRoleComponent implements WalkerInterface, WriterInterface
{
	public static function canWalk(
		mixed $source,
		MappingContext $context,
	): bool {
		return false;
	}

	public function walk(
		mixed $source,
		MappingContext $context,
		Closure $visit,
	): void {
	}

	public static function canWrite(
		mixed $target,
		MappingContext $context,
	): bool {
		return false;
	}

	public function prepare(
		mixed $target,
		MappingContext $context,
	): mixed {
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
