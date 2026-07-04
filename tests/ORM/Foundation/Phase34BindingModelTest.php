<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Foundation;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class Phase34BindingModelTest extends TestCase
{
	public function testNoSeparateBindingOrPersistenceGraphClassesWereIntroduced(): void
	{
		$forbidden = [
			'BindingNode',
			'BindingGraph',
			'PersistenceGraph',
			'QueryGraph',
			'BindingTree',
		];

		foreach ($this->phpFiles(dirname(__DIR__, 3) . '/src') as $path) {
			$contents = file_get_contents($path);
			self::assertIsString($contents);

			foreach ($forbidden as $name) {
				self::assertStringNotContainsString('class ' . $name, $contents, $path);
				self::assertStringNotContainsString('interface ' . $name, $contents, $path);
				self::assertStringNotContainsString('enum ' . $name, $contents, $path);
			}
		}
	}

	public function testSelectQueryWasNotCoupledToOrmPersistence(): void
	{
		$contents = file_get_contents(dirname(__DIR__, 3) . '/src/Query/SelectQuery.php');

		self::assertIsString($contents);
		self::assertStringNotContainsString('ON\\Data\\ORM\\Persistence', $contents);
		self::assertStringNotContainsString('RepresentationBinding', $contents);
		self::assertStringNotContainsString('RecordState', $contents);
	}

	/**
	 * @return list<string>
	 */
	private function phpFiles(string $root): array
	{
		$files = [];
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
		foreach ($iterator as $file) {
			if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}

			$files[] = $file->getPathname();
		}

		return $files;
	}
}
