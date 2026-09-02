<?php

declare(strict_types=1);

namespace Tests\ON\Data\Definition\Plugin;

use ON\Data\Definition\Display\ImageDisplay;
use ON\Data\Definition\Interface\DropdownInterface;
use ON\Data\Definition\Plugin\CycleCollectionPlugin;
use ON\Data\Definition\Plugin\UiDefinitionPlugin;
use ON\Data\Definition\Registry;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Fixture\CustomMapper;
use Tests\ON\Data\PlainDataAsserts;

final class DefinitionPluginTest extends TestCase
{
	use PlainDataAsserts;

	public function testUiPluginRoundTripsDisplayAndInterface(): void
	{
		$registry = new Registry();
		$field = $registry->collection('users')
			->field('avatar', 'string')
			->extend(
				UiDefinitionPlugin::create()
					->display(ImageDisplay::class)->displayAsCircle(true)->end()
					->interface(DropdownInterface::class)->choices(['a' => 'A'])->end(),
			);

		$all = $registry->all();
		self::assertPlainData($all);
		self::assertTrue($field->hasPlugin(UiDefinitionPlugin::class));

		$restored = new Registry($all);
		$ui = $restored->getCollection('users')?->getField('avatar')?->getPlugin(UiDefinitionPlugin::class);
		$display = $ui?->getDisplay();
		$interface = $ui?->getInterface();

		self::assertInstanceOf(ImageDisplay::class, $display);
		self::assertTrue($display->shouldDisplayAsCircle());
		self::assertSame($ui, $display->end());
		self::assertInstanceOf(DropdownInterface::class, $interface);
		self::assertSame(['a' => 'A'], $interface->getChoices());
		self::assertSame($all, $restored->all());
	}

	public function testCycleCollectionPluginRoundTripsBridgeMetadata(): void
	{
		$registry = new Registry();
		$users = $registry->collection('users')
			->extend(
				CycleCollectionPlugin::create()
					->entity(stdClass::class)
					->repository('UserRepository')
					->mapper(CustomMapper::class)
					->scope('NotDeletedScope'),
			);

		$cycle = $users->getPlugin(CycleCollectionPlugin::class);
		self::assertSame(stdClass::class, $cycle?->getEntity());
		self::assertSame('UserRepository', $cycle?->getRepository());
		self::assertSame(CustomMapper::class, $cycle?->getMapper());
		self::assertSame('NotDeletedScope', $cycle?->getScope());

		$restored = new Registry($registry->all());
		$restoredCycle = $restored->getCollection('users')?->getPlugin(CycleCollectionPlugin::class);
		self::assertSame(CustomMapper::class, $restoredCycle?->getMapper());
	}

	public function testExtendReplacesTheSamePluginClass(): void
	{
		$field = (new Registry())->collection('users')->field('name', 'string');
		$field->extend(
			UiDefinitionPlugin::create()->interface(DropdownInterface::class)->choices(['one' => 'One'])->end(),
		);
		$field->extend(
			UiDefinitionPlugin::create()->interface(DropdownInterface::class)->choices(['two' => 'Two'])->end(),
		);

		self::assertSame(
			['two' => 'Two'],
			$field->getPlugin(UiDefinitionPlugin::class)?->getInterface()->getChoices(),
		);
	}

	public function testGetPluginReturnsTheSameBoundInstance(): void
	{
		$field = (new Registry())->collection('users')->field('name', 'string');
		$field->extend(UiDefinitionPlugin::create());

		self::assertSame(
			$field->getPlugin(UiDefinitionPlugin::class),
			$field->getPlugin(UiDefinitionPlugin::class),
		);
	}

	public function testMissingPluginIsNull(): void
	{
		$field = (new Registry())->collection('users')->field('name', 'string');

		self::assertFalse($field->hasPlugin(UiDefinitionPlugin::class));
		self::assertNull($field->getPlugin(UiDefinitionPlugin::class));
	}
}
