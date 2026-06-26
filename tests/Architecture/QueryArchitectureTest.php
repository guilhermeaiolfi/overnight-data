<?php

declare(strict_types=1);

namespace Tests\ON\Data\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class QueryArchitectureTest extends TestCase
{
	public function testQueryNamespaceRemainsDatabaseIndependent(): void
	{
		$root = dirname(__DIR__, 2) . '/src/Query';
		$forbiddenPatterns = [
			'Cycle\\',
			'Doctrine\\',
			'PDO',
		];

		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

		foreach ($iterator as $file) {
			/** @var SplFileInfo $file */
			if (! $file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}

			$contents = strtolower((string) file_get_contents($file->getPathname()));

			foreach ($forbiddenPatterns as $pattern) {
				self::assertStringNotContainsString(
					strtolower($pattern),
					$contents,
					sprintf('Forbidden query-layer pattern "%s" found in %s', $pattern, $file->getPathname()),
				);
			}
		}
	}

	public function testNeutralDatabaseSurfaceDoesNotExposeCycleNamespacesOutsideBackendFolder(): void
	{
		$root = dirname(__DIR__, 2) . '/src/Database';
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

		foreach ($iterator as $file) {
			/** @var SplFileInfo $file */
			if (! $file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}

			$normalizedPath = str_replace('\\', '/', $file->getPathname());

			if (
				str_contains($normalizedPath, '/src/Database/Cycle/')
				|| str_ends_with($normalizedPath, '/src/Database/Database.php')
			) {
				continue;
			}

			$contents = (string) file_get_contents($file->getPathname());

			self::assertStringNotContainsString(
				'Cycle\\',
				$contents,
				sprintf('Neutral database surface leaked Cycle namespace in %s', $file->getPathname()),
			);
		}
	}

	public function testNeutralQueryAndDatabaseInfrastructureDoesNotDependOnConcreteRelationTypes(): void
	{
		$root = dirname(__DIR__, 2);
		$targets = [
			$root . '/src/Query',
			$root . '/src/Database',
			$root . '/src/Definition/Registry.php',
		];
		$allowedPaths = [
			'/src/Query/Relation/',
			'/src/Query/Relation/Loader/',
			'/src/Definition/Relation/',
		];
		$forbiddenStrings = [
			'HasOneRelation',
			'HasManyRelation',
			'BelongsToRelation',
			'M2MRelation',
			'FirstOfManyRelation',
			'M2MThrough',
			'HasOneLoader',
			'HasManyLoader',
			'BelongsToLoader',
			'M2MLoader',
			'FirstOfManyLoader',
			'->getParentRelation(',
			'->getParentSource(',
			'->innerKeys(',
			'->outerKeys(',
			'->getWhere(',
			'->getOrderBy(',
			'->getThrough(',
			'->throughInnerKeys(',
			'->throughOuterKeys(',
		];

		foreach ($this->phpFiles($targets) as $path) {
			$normalizedPath = str_replace('\\', '/', $path);

			if ($this->isAllowedRelationOwnershipPath($normalizedPath, $allowedPaths)) {
				continue;
			}

			$contents = (string) file_get_contents($path);

			foreach ($forbiddenStrings as $forbidden) {
				self::assertStringNotContainsString(
					$forbidden,
					$contents,
					sprintf('Relation-specific coupling "%s" leaked into neutral infrastructure at %s', $forbidden, $normalizedPath),
				);
			}
		}
	}

	/**
	 * @param list<string> $targets
	 * @return list<string>
	 */
	private function phpFiles(array $targets): array
	{
		$files = [];

		foreach ($targets as $target) {
			if (is_file($target)) {
				$files[] = $target;
				continue;
			}

			$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target));

			foreach ($iterator as $file) {
				/** @var SplFileInfo $file */
				if (! $file->isFile() || $file->getExtension() !== 'php') {
					continue;
				}

				$files[] = $file->getPathname();
			}
		}

		return $files;
	}

	/**
	 * @param list<string> $allowedPaths
	 */
	private function isAllowedRelationOwnershipPath(string $path, array $allowedPaths): bool
	{
		foreach ($allowedPaths as $allowedPath) {
			if (str_contains($path, $allowedPath)) {
				return true;
			}
		}

		return false;
	}
}
