<?php

declare(strict_types=1);

namespace Tests\ON\Data\Architecture;

use ON\Data\Definition\Collection\Collection;
use ON\Data\Definition\Display\RawDisplay;
use ON\Data\Definition\Field\Field;
use ON\Data\Definition\Interface\AbstractInterface;
use ON\Data\Definition\Relation\AbstractRelation;
use ON\Data\Definition\Relation\M2MThrough;
use ON\Data\Definition\View\ViewDefinition;
use ON\Data\Definition\View\ViewField;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class Phase5ArchitectureTest extends TestCase
{
	public function testCollectionNoLongerExposesPublicBindDefinitionArrayMethod(): void
	{
		self::assertFalse(method_exists(Collection::class, 'bindDefinitionArray'));
	}

	public function testRawHydrationConstructorParametersAreAbsentFromPublicConstructors(): void
	{
		$classes = [
			Collection::class,
			Field::class,
			AbstractRelation::class,
			RawDisplay::class,
			AbstractInterface::class,
			M2MThrough::class,
			ViewDefinition::class,
			ViewField::class,
		];

		foreach ($classes as $class) {
			$constructor = new ReflectionMethod($class, '__construct');
			self::assertLessThanOrEqual(1, $constructor->getNumberOfParameters(), $class . ' still exposes hydration parameters.');
		}
	}
}
