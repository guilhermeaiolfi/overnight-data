<?php

declare(strict_types=1);

namespace Tests\ON\Data\Definition\Plugin;

use InvalidArgumentException;
use LogicException;
use ON\Data\Definition\Display\ImageDisplay;
use ON\Data\Definition\Exception\InvalidDefinitionClassException;
use ON\Data\Definition\Field\Field;
use ON\Data\Definition\Interface\DropdownInterface;
use ON\Data\Definition\Plugin\CycleCollectionPlugin;
use ON\Data\Definition\Plugin\UiDefinitionPlugin;
use ON\Data\Definition\Registry;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Fixture\CustomMapper;
use Tests\ON\Data\Fixture\ValuePlugin;
use Tests\ON\Data\PlainDataAsserts;

final class DefinitionPluginTest extends TestCase
{
	use PlainDataAsserts;

	public function testUiPluginRoundTripsDisplayAndInterface(): void
	{
		$registry = new Registry();
		$field = $registry->collection('users')
			->field('avatar', 'string')
			->extend(UiDefinitionPlugin::class)
				->display(ImageDisplay::class)->displayAsCircle(true)->end()
				->interface(DropdownInterface::class)->choices(['a' => 'A'])->end()
				->end();

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
			->extend(CycleCollectionPlugin::class)
				->entity(stdClass::class)
				->repository('UserRepository')
				->mapper(CustomMapper::class)
				->scope('NotDeletedScope')
				->end();

		$cycle = $users->getPlugin(CycleCollectionPlugin::class);
		self::assertSame(stdClass::class, $cycle?->getEntity());
		self::assertSame('UserRepository', $cycle?->getRepository());
		self::assertSame(CustomMapper::class, $cycle?->getMapper());
		self::assertSame('NotDeletedScope', $cycle?->getScope());

		$restored = new Registry($registry->all());
		$restoredCycle = $restored->getCollection('users')?->getPlugin(CycleCollectionPlugin::class);
		self::assertSame(CustomMapper::class, $restoredCycle?->getMapper());
	}

	public function testExtendWithAValueStaysOnTheHost(): void
	{
		$registry = new Registry();
		$users = $registry->collection('users');

		self::assertSame($users, $users->extend(ValuePlugin::class, 'deleted_at'));
		self::assertSame('deleted_at', $users->getPlugin(ValuePlugin::class)?->getValue());

		$all = $registry->all();
		self::assertPlainData($all);
		self::assertSame(
			'deleted_at',
			$all['collections']['users']['extend'][ValuePlugin::class]['value'],
		);

		$restored = new Registry($all);
		self::assertSame(
			'deleted_at',
			$restored->getCollection('users')?->getPlugin(ValuePlugin::class)?->getValue(),
		);
	}

	public function testExtendValueCanBeNull(): void
	{
		$users = (new Registry())->collection('users');

		self::assertSame($users, $users->extend(ValuePlugin::class, null));
		self::assertNull($users->getPlugin(ValuePlugin::class)?->getValue());
	}

	public function testExtendWithoutAValueEntersThePlugin(): void
	{
		$users = (new Registry())->collection('users');
		$plugin = $users->extend(ValuePlugin::class);

		self::assertInstanceOf(ValuePlugin::class, $plugin);
		self::assertNull($plugin->getValue());
		self::assertSame($users, $plugin->value('later')->end());
		self::assertSame('later', $users->getPlugin(ValuePlugin::class)?->getValue());
	}

	public function testExtendRejectsMoreThanOneValue(): void
	{
		$users = (new Registry())->collection('users');

		$this->expectException(InvalidArgumentException::class);
		$users->extend(ValuePlugin::class, 'a', 'b');
	}

	public function testExtendCreatesInPlaceAndEndReturnsTheHost(): void
	{
		$field = (new Registry())->collection('users')->field('name', 'string');
		$plugin = $field->extend(UiDefinitionPlugin::class);

		self::assertSame($field, $plugin->end());
		self::assertSame($plugin, $field->getPlugin(UiDefinitionPlugin::class));
		self::assertSame($plugin, $field->extend(UiDefinitionPlugin::class));
	}

	public function testExtendResumesTheSamePluginClass(): void
	{
		$field = (new Registry())->collection('users')->field('name', 'string');
		$field->extend(UiDefinitionPlugin::class)
			->interface(DropdownInterface::class)->choices(['one' => 'One'])->end();
		$field->extend(UiDefinitionPlugin::class)
			->interface(DropdownInterface::class)->choices(['two' => 'Two'])->end();

		self::assertSame(
			['two' => 'Two'],
			$field->getPlugin(UiDefinitionPlugin::class)?->getInterface()->getChoices(),
		);
	}

	public function testGetPluginReturnsTheSameBoundInstance(): void
	{
		$field = (new Registry())->collection('users')->field('name', 'string');
		$field->extend(UiDefinitionPlugin::class);

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

	public function testExtendRejectsANonPluginClass(): void
	{
		$field = (new Registry())->collection('users')->field('name', 'string');

		$this->expectException(InvalidDefinitionClassException::class);
		$field->extend(Field::class);
	}

	public function testPluginCreateIsNotAnAuthoringPath(): void
	{
		$this->expectException(LogicException::class);
		UiDefinitionPlugin::create();
	}
}
