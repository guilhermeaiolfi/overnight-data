<?php

declare(strict_types=1);

namespace Tests\ON\Data\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class SchemaArchitectureTest extends TestCase
{
	public function testSchemaDoesNotDependOnQueryOrOrm(): void
	{
		$root = dirname(__DIR__, 2) . '/src/Schema';
		$forbiddenPatterns = [
			'use ON\\Data\\Query\\',
			'use ON\\Data\\ORM\\',
			'ON\\Data\\Query\\',
			'ON\\Data\\ORM\\',
		];

		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

		foreach ($iterator as $file) {
			/** @var SplFileInfo $file */
			if (! $file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}

			$contents = (string) file_get_contents($file->getPathname());

			foreach ($forbiddenPatterns as $pattern) {
				self::assertStringNotContainsString(
					$pattern,
					$contents,
					sprintf('Schema must not depend on Query or ORM ("%s" in %s)', $pattern, $file->getPathname()),
				);
			}
		}
	}

	public function testQueryDoesNotImportOrmRepresentationSchema(): void
	{
		$root = dirname(__DIR__, 2) . '/src/Query';
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

		foreach ($iterator as $file) {
			/** @var SplFileInfo $file */
			if (! $file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}

			$contents = (string) file_get_contents($file->getPathname());
			self::assertStringNotContainsString(
				'ON\\Data\\ORM\\Representation\\Schema\\Representation',
				$contents,
				$file->getPathname(),
			);
		}
	}
}
