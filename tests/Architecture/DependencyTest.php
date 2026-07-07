<?php

declare(strict_types=1);

namespace Tests\ON\Data\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class DependencyTest extends TestCase
{
	public function testProductionCodeDoesNotReferenceForbiddenNamespaces(): void
	{
		$root = dirname(__DIR__, 2) . '/src';
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
		$forbiddenPatterns = [
			'Cycle\\',
			'Doctrine\\',
			'ON\\DB\\',
			'ON\\ORM\\',
			'ON\\RestApi\\',
			'Overnight',
			'Definition\\Collection\\PrimaryKeyDefinition',
			'Definition\\Collection\\PrimaryKeyValue',
		];

		foreach ($iterator as $file) {
			/** @var SplFileInfo $file */
			if (! $file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}

			$contents = file_get_contents($file->getPathname());
			self::assertNotFalse($contents);
			$normalizedPath = str_replace('\\', '/', $file->getPathname());

			foreach ($forbiddenPatterns as $pattern) {
				if (
					$pattern === 'Cycle\\'
					&& (
						str_contains($normalizedPath, '/src/Database/Cycle/')
						|| str_ends_with($normalizedPath, '/src/Database/DataRuntime.php')
					)
				) {
					continue;
				}

				self::assertStringNotContainsString(
					$pattern,
					$contents,
					sprintf('Forbidden namespace "%s" found in %s', $pattern, $file->getPathname()),
				);
			}
		}
	}
}
