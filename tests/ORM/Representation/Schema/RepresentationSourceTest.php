<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Representation\Schema;

use ON\Data\Definition\Registry;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSource;
use PHPUnit\Framework\TestCase;

final class RepresentationSourceTest extends TestCase
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

	public function testFromRepresentationSchemaGroupsFieldsBySourcePath(): void
	{
		$registry = new Registry();
		$users = $registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		$companies = $registry->collection('companies')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		$schema = new RepresentationSchema($users);
		$schema->addField(new RepresentationFieldSchema('name', $users, 'name'));
		$schema->addField(new RepresentationFieldSchema('companyName', $companies, 'name', sourcePath: ['company']));
		$schema->addField(new RepresentationFieldSchema('companyId', $companies, 'id', sourcePath: ['company']));

		$sources = RepresentationSource::fromRepresentationSchema($schema);

		self::assertCount(2, $sources);
		self::assertSame([], $sources[0]->getPath());
		self::assertSame(['company'], $sources[1]->getPath());
		self::assertSame('companies', $sources[1]->getCollection()->getName());
		self::assertTrue($sources[1]->hasField('name'));
		self::assertFalse($sources[1]->hasField('companyName'));
		self::assertSame('companyName', $sources[1]->getFieldPath('name'));
		self::assertSame('companyId', $sources[1]->getFieldPath('id'));
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
