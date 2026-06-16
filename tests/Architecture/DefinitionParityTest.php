<?php

declare(strict_types=1);

namespace Tests\ON\Data\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class DefinitionParityTest extends TestCase
{
	public function testEverySourceDefinitionFileHasAMigratedTargetFile(): void
	{
		$sourceRoot = dirname(__DIR__, 2) . '/.cache/overnight/src/ORM/Definition';
		$targetRoot = dirname(__DIR__, 2) . '/src/Definition';

		$sourceFiles = [];
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot));
		foreach ($iterator as $file) {
			/** @var SplFileInfo $file */
			if (! $file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}

			$sourceFiles[] = str_replace('\\', '/', $file->getPathname());
		}

		$expected = [];
		foreach ($sourceFiles as $sourceFile) {
			$relative = substr($sourceFile, strlen(str_replace('\\', '/', $sourceRoot)) + 1);
			if (in_array($relative, ['Collection/PrimaryKeyDefinition.php', 'Collection/PrimaryKeyValue.php'], true)) {
				continue;
			}
			$expected[] = str_replace('\\', '/', $targetRoot) . '/' . ($relative === 'Display/DateTimeDisplay.php' ? 'Display/DatetimeDisplay.php' : $relative);
		}

		foreach ($expected as $targetFile) {
			self::assertFileExists($targetFile);
		}
	}
}
