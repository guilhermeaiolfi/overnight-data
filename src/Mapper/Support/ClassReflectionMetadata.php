<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Support;

use ReflectionClass;
use ReflectionProperty;

/** @internal */
final class ClassReflectionMetadata
{
	/** @var ReflectionClass<object>|null */
	public ?ReflectionClass $reflection = null;

	public ?ObjectPropertyMatcher $targetMatcher = null;

	/** @var array<string, ReflectionProperty> */
	public array $sourceProperties = [];

	/** @var array<string, string> */
	public array $mappedSourceNames = [];

	/** @var array<string, class-string>|null */
	public ?array $imports = null;
}
