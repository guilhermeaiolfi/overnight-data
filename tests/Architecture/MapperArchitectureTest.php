<?php

declare(strict_types=1);

namespace Tests\ON\Data\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class MapperArchitectureTest extends TestCase
{
	public function testProductionCodeDoesNotReferenceExpandedForbiddenNamespaces(): void
	{
		$root = dirname(__DIR__, 2) . '/src';
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
		$forbiddenPatterns = [
			'Cycle\\',
			'Doctrine\\',
			'ON\\Application',
			'ON\\Container',
			'ON\\ORM\\',
			'ON\\RestApi\\',
			'Psr\\Container',
			'Psr\\Http',
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
