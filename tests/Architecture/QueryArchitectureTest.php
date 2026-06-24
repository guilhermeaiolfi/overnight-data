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
		$roots = [
			dirname(__DIR__, 2) . '/src/Query',
			dirname(__DIR__, 2) . '/src/Database',
		];
		$forbiddenPatterns = [
			'Cycle\\',
			'Doctrine\\',
			'PDO',
		];

		foreach ($roots as $root) {
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
	}

	public function testComposerKeepsTheCorePackageFreeOfCycleDependencies(): void
	{
		$composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
		$packages = array_merge(
			array_keys($composer['require'] ?? []),
			array_keys($composer['require-dev'] ?? []),
		);

		foreach ($packages as $package) {
			self::assertStringStartsNotWith('cycle/', $package);
		}
	}
}
