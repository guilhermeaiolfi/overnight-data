<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\ORM\Compiler\ProjectionSource;
use ON\Data\ORM\State\RepresentationFieldSchema;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class RepresentationSourcePathKeyTest extends TestCase
{
	use OrmFixture;

	public function testRootSourcePathKeyIsEmptyString(): void
	{
		self::assertSame('', RepresentationFieldSchema::sourcePathKey([]));
	}

	public function testSingleLevelSourcePathKey(): void
	{
		self::assertSame('company', RepresentationFieldSchema::sourcePathKey(['company']));
	}

	public function testNestedSourcePathKey(): void
	{
		self::assertSame('posts.comments', RepresentationFieldSchema::sourcePathKey(['posts', 'comments']));
	}

	public function testProjectionSourcePathKeyMatchesFieldSchema(): void
	{
		$users = $this->users();
		$source = new ProjectionSource(
			['company'],
			$users,
			[new RepresentationFieldSchema('companyName', $users, 'name', sourcePath: ['company'])],
		);

		self::assertSame(
			RepresentationFieldSchema::sourcePathKey(['company']),
			$source->getPathKey(),
		);
	}
}
