<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

final class MapperTestState
{
	/**
	 * @var array<class-string, int>
	 */
	public static array $constructed = [];

	/**
	 * @var array<class-string, int>
	 */
	public static array $canMapCalls = [];

	/**
	 * @var array<class-string, int>
	 */
	public static array $mapCalls = [];

	/**
	 * @var list<array{class: class-string, arguments: list<mixed>, path: string, collection: bool}>
	 */
	public static array $contexts = [];

	public static function reset(): void
	{
		self::$constructed = [];
		self::$canMapCalls = [];
		self::$mapCalls = [];
		self::$contexts = [];
	}

	public static function recordConstruction(string $class): void
	{
		self::$constructed[$class] = (self::$constructed[$class] ?? 0) + 1;
	}

	public static function recordCanMap(string $class): void
	{
		self::$canMapCalls[$class] = (self::$canMapCalls[$class] ?? 0) + 1;
	}

	public static function recordMap(string $class, array $arguments, string $path, bool $collection): void
	{
		self::$mapCalls[$class] = (self::$mapCalls[$class] ?? 0) + 1;
		self::$contexts[] = [
			'class' => $class,
			'arguments' => $arguments,
			'path' => $path,
			'collection' => $collection,
		];
	}
}
