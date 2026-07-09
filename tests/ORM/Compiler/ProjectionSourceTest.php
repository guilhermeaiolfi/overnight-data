<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Compiler;

use ON\Data\Definition\Registry;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationSource;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use PHPUnit\Framework\TestCase;

final class ProjectionSourceTest extends TestCase
{
	public function testRootSourcePathKeyMatchesCanonicalRepresentationSourceKey(): void
	{
		self::assertSame(
			RepresentationFieldSchema::sourcePathKey([]),
			$this->source([])->getPathKey()
		);
	}

	public function testSingleLevelSourcePathKeyMatchesCanonicalRepresentationSourceKey(): void
	{
		self::assertSame(
			RepresentationFieldSchema::sourcePathKey(['company']),
			$this->source(['company'])->getPathKey()
		);
	}

	public function testNestedSourcePathKeyMatchesCanonicalRepresentationSourceKey(): void
	{
		self::assertSame(
			RepresentationFieldSchema::sourcePathKey(['posts', 'comments']),
			$this->source(['posts', 'comments'])->getPathKey()
		);
	}

	/**
	 * @param list<string> $path
	 */
	private function source(array $path): RepresentationSource
	{
		$collection = (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end();

		return new RepresentationSource($path, $collection, []);
	}
}
