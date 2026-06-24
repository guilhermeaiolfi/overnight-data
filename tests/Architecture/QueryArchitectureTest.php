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
}
