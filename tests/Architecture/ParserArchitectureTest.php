<?php

declare(strict_types=1);

namespace Tests\ON\Data\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ParserArchitectureTest extends TestCase
{
	public function testParserNamespaceDoesNotDependOnForbiddenLayers(): void
	{
		$root = dirname(__DIR__, 2) . '/src/Query/Result/Parser';
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
		$forbiddenPatterns = [
			'ON\\Data\\Definition',
			'ON\\Data\\Key',
			'ON\\Data\\Mapper',
			'ON\\Data\\Query\\Relation',
			'ON\\Data\\Database',
			'Cycle\\ORM',
			'Cycle\\Database\\Query',
			'Cycle\\Database\\Injection\\Parameter',
			'Doctrine\\DBAL',
		];

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
					sprintf('Forbidden parser dependency "%s" found in %s', $pattern, $file->getPathname()),
				);
			}
		}
	}
}
