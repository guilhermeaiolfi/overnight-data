<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Representation\Schema;

use ON\Data\Definition\Registry;
use ON\Data\ORM\Representation\Sync\RepresentationSourceReader;
use ON\Data\Schema\RepresentationFieldRole;
use ON\Data\Schema\RepresentationFieldSchema;
use ON\Data\Schema\RepresentationSource;
use PHPUnit\Framework\TestCase;

final class QuerySourceKeyTest extends TestCase
{
	public function testGetIdentityReadsSourceQualifiedSchemaPath(): void
	{
		$registry = new Registry();
		$registry->collection('companies')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		$companies = $registry->getCollection('companies');
		$path = 'company.id';
		$source = new RepresentationSource(
			['company'],
			$companies,
			[
				new RepresentationFieldSchema('name', $companies, 'name', sourcePath: ['company']),
				new RepresentationFieldSchema(
					$path,
					$companies,
					'id',
					writable: false,
					sourcePath: ['company'],
					role: RepresentationFieldRole::Implicit,
				),
			],
		);

		$identity = RepresentationSourceReader::keyFromRow($source, [
			'id' => 1,
			'name' => 'Acme',
			$path => 5,
		]);

		self::assertNotNull($identity);
		self::assertSame(['id' => 5], $identity->getValues());
	}

	public function testGetIdentityReturnsNullWhenAPrimaryKeyPlaceIsMissingFromTheRow(): void
	{
		$registry = new Registry();
		$registry->collection('companies')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		$companies = $registry->getCollection('companies');
		$source = new RepresentationSource(
			['company'],
			$companies,
			[
				new RepresentationFieldSchema('name', $companies, 'name', sourcePath: ['company']),
				new RepresentationFieldSchema(
					'company.id',
					$companies,
					'id',
					writable: false,
					sourcePath: ['company'],
					role: RepresentationFieldRole::Implicit,
				),
			],
		);

		self::assertNull(RepresentationSourceReader::keyFromRow($source, [
			'name' => 'Acme',
		]));
	}

	public function testGetIdentityReturnsNullWhenAPrimaryKeyHasNoPlacePath(): void
	{
		$registry = new Registry();
		$registry->collection('companies')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		$companies = $registry->getCollection('companies');
		$source = new RepresentationSource(
			['company'],
			$companies,
			[
				new RepresentationFieldSchema('name', $companies, 'name', sourcePath: ['company']),
			],
		);

		self::assertNull(RepresentationSourceReader::keyFromRow($source, [
			'id' => 5,
			'name' => 'Acme',
		]));
	}
}
