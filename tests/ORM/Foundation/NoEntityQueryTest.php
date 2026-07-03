<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Foundation;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class NoEntityQueryTest extends TestCase
{
	public function testNoEntityQueryClassIsIntroduced(): void
	{
		self::assertFalse(class_exists('ON\\Data\\ORM\\EntityQuery'));
		self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/src/ORM/EntityQuery.php');
	}

	public function testSourceDoesNotUseEntityQueryVocabulary(): void
	{
		foreach ($this->phpFiles(dirname(__DIR__, 3) . '/src') as $path) {
			self::assertStringNotContainsString(
				'EntityQuery',
				(string) file_get_contents($path),
				$path,
			);
		}
	}

	/**
	 * @return list<string>
	 */
	private function phpFiles(string $root): array
	{
		$files = [];
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

		foreach ($iterator as $file) {
			/** @var SplFileInfo $file */
			if (! $file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}

			$files[] = $file->getPathname();
		}

		return $files;
	}
}
