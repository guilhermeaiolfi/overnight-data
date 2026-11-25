<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Support;

use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\MappingNode;
use ReflectionReference;

final class ArrayPathExpander
{
	/**
	 * @param array<mixed> $source
	 *
	 * @return array<mixed>
	 */
	public static function undot(array $source): array
	{
		return self::expand($source);
	}

	/**
	 * @param array<mixed> $source
	 *
	 * @return array<mixed>
	 */
	public static function expand(array $source): array
	{
		$result = [];

		foreach ($source as $key => $value) {
			if (is_int($key) || ! is_string($key) || ! str_contains($key, '.')) {
				self::assignLiteral($result, $key, $value);

				continue;
			}

			$segments = self::parseSegments($key);
			self::assignPath($result, $segments, $value, $key);
		}

		return $result;
	}

	/**
	 * @param array<mixed> $target
	 * @param string|int $key
	 */
	private static function assignLiteral(array &$target, string|int $key, mixed $value): void
	{
		if (! array_key_exists($key, $target)) {
			$target[$key] = $value;

			return;
		}

		$current = $target[$key];
		if (is_array($current) && is_array($value)) {
			$target[$key] = self::mergeBranches($current, $value, (string) $key);

			return;
		}

		throw new MappingException(sprintf("Conflicting dotted-key path '%s'.", (string) $key));
	}

	/**
	 * @param array<mixed> $target
	 * @param list<string> $segments
	 */
	private static function assignPath(array &$target, array $segments, mixed $value, string $originalKey): void
	{
		$current = &$target;
		$currentPath = '';
		$lastIndex = count($segments) - 1;

		foreach ($segments as $index => $segment) {
			$currentPath = $currentPath === '' ? $segment : $currentPath . '.' . $segment;
			$isLeaf = $index === $lastIndex;

			if ($isLeaf) {
				if (! array_key_exists($segment, $current)) {
					$current[$segment] = $value;

					return;
				}

				$existing = $current[$segment];
				if (is_array($existing) && is_array($value)) {
					self::detachReference($current, $segment);
					$current[$segment] = self::mergeBranches($existing, $value, $currentPath);

					return;
				}

				throw new MappingException(sprintf("Conflicting dotted-key path '%s'.", $currentPath));
			}

			if (! array_key_exists($segment, $current)) {
				$current[$segment] = [];
			}

			if (! is_array($current[$segment])) {
				throw new MappingException(sprintf("Conflicting dotted-key path '%s'.", $currentPath));
			}

			self::detachReference($current, $segment);
			$current = &$current[$segment];
		}

		throw new MappingException(sprintf("Malformed dotted key '%s'.", $originalKey));
	}

	/** @param array<mixed> $branch */
	private static function detachReference(array &$branch, string $key): void
	{
		if (ReflectionReference::fromArrayElement($branch, $key) !== null) {
			// Detach the entry before writing through it so expansion cannot modify the source.
			$branch = array_replace($branch, [$key => $branch[$key]]);
		}
	}

	/**
	 * @param array<mixed> $left
	 * @param array<mixed> $right
	 *
	 * @return array<mixed>
	 */
	private static function mergeBranches(array $left, array $right, string $path, int $depth = 0): array
	{
		if ($depth > MappingNode::MAX_DEPTH) {
			throw new MappingException(sprintf(
				"Maximum dotted-key merge depth of %d exceeded at path '%s'. The source may contain a recursive array reference.",
				MappingNode::MAX_DEPTH,
				$path,
			));
		}

		$replacements = [];
		foreach ($right as $key => $value) {
			if (! array_key_exists($key, $left)) {
				$replacements[$key] = $value;

				continue;
			}

			$childPath = $path === '' ? (string) $key : $path . '.' . (string) $key;
			$existing = $left[$key];
			if (is_array($existing) && is_array($value)) {
				$replacements[$key] = self::mergeBranches($existing, $value, $childPath, $depth + 1);

				continue;
			}

			throw new MappingException(sprintf("Conflicting dotted-key path '%s'.", $childPath));
		}

		return array_replace($left, $replacements);
	}

	/**
	 * @return list<string>
	 */
	private static function parseSegments(string $key): array
	{
		if ($key === '' || str_starts_with($key, '.') || str_ends_with($key, '.') || str_contains($key, '..')) {
			throw new MappingException(sprintf("Malformed dotted key '%s'.", $key));
		}

		$segments = explode('.', $key, MappingNode::MAX_DEPTH + 1);
		if (count($segments) > MappingNode::MAX_DEPTH) {
			throw new MappingException(sprintf(
				"Maximum dotted-key depth of %d exceeded at path '%s'.",
				MappingNode::MAX_DEPTH,
				$key,
			));
		}

		return $segments;
	}
}
