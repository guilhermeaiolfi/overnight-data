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
			'ON\\ORM\\',
			'ON\\RestApi\\',
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

			foreach ($forbiddenPatterns as $pattern) {
				self::assertStringNotContainsString(
					$pattern,
					$contents,
					sprintf('Forbidden namespace "%s" found in %s', $pattern, $file->getPathname()),
				);
			}
		}
	}
}
