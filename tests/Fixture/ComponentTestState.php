<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

final class ComponentTestState
{
	/**
	 * @var array<class-string, int>
	 */
	public static array $constructed = [];

	/**
	 * @var array<class-string, int>
	 */
	public static array $selectionCalls = [];

	/**
	 * @var array<class-string, int>
	 */
	public static array $runtimeCalls = [];

	/**
	 * @var list<array{class: class-string, path: string}>
	 */
	public static array $paths = [];

	public static function reset(): void
	{
		self::$constructed = [];
		self::$selectionCalls = [];
		self::$runtimeCalls = [];
		self::$paths = [];
	}

	public static function recordConstruction(string $class): void
	{
		self::$constructed[$class] = (self::$constructed[$class] ?? 0) + 1;
	}

	public static function recordSelection(string $class): void
	{
		self::$selectionCalls[$class] = (self::$selectionCalls[$class] ?? 0) + 1;
	}

	public static function recordRuntime(string $class, string $path = ''): void
	{
		self::$runtimeCalls[$class] = (self::$runtimeCalls[$class] ?? 0) + 1;
		self::$paths[] = ['class' => $class, 'path' => $path];
	}
}
