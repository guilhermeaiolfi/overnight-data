<?php

declare(strict_types=1);

namespace Tests\ON\Data\Definition;

use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Interface\InterfaceInterface;
use ON\Data\Definition\Interface\ManyToManyInterface;
use ON\Data\Definition\Interface\MapInterface;
use ON\Data\Definition\Interface\TagsInterface;
use ON\Data\Definition\Interface\ToggleInterface;
use ON\Data\Definition\Interface\WYSIWYGInterface;
use ON\Data\Definition\Plugin\UiDefinitionPlugin;
use ON\Data\Definition\Registry;
use PHPUnit\Framework\TestCase;

final class InterfaceDefinitionTest extends TestCase
{
	public function testInterfaceClassesKeepCurrentDefaultsAndOptions(): void
	{
		$field = (new Registry())->collection('post')->field('title', 'string');
		$field->extend(UiDefinitionPlugin::class)->interface(ManyToManyInterface::class)->end();
		$many = $field->getPlugin(UiDefinitionPlugin::class)?->getInterface();
		self::assertInstanceOf(ManyToManyInterface::class, $many);

		$many
			->type('table')
			->template('{{title}}')
			->columns(['title'])
			->allowCreation(true)
			->allowSelection(true)
			->allowDuplication(true)
			->allowSearch(true)
			->showLink(true)
			->itemsPerPage(25);

		self::assertSame('table', $many->getType());
		self::assertSame('{{title}}', $many->getTemplate());
		self::assertSame(['title'], $many->getColumns());
		self::assertTrue($many->isAllowCreation());
		self::assertTrue($many->isAllowSelection());
		self::assertTrue($many->isAllowDuplication());
		self::assertTrue($many->isAllowSearch());
		self::assertTrue($many->shouldShowLink());
		self::assertSame(25, $many->getItemsPerPage());
		self::assertSame($field->getPlugin(UiDefinitionPlugin::class), $many->end());

		$tagsField = $field->end()->field('tags', 'string');
		$tagsField->extend(UiDefinitionPlugin::class)->interface(TagsInterface::class)->end();
		$tags = $tagsField->getPlugin(UiDefinitionPlugin::class)?->getInterface();
		self::assertInstanceOf(TagsInterface::class, $tags);
		$tags
			->whitespace(TagsInterface::WHITESPACE_REPLACE_WITH_HYPHEN)
			->capitalization(TagsInterface::CAPITALIZATION_CONVERT_LOWERCASE)
			->allowOther(true)
			->az(true)
			->presetTags(['news'])
			->placeholder('Pick tags');
		self::assertSame(TagsInterface::WHITESPACE_REPLACE_WITH_HYPHEN, $tags->getWhitespace());
		self::assertSame(TagsInterface::CAPITALIZATION_CONVERT_LOWERCASE, $tags->getCapitalization());
		self::assertTrue($tags->isAllowOther());
		self::assertTrue($tags->isAZ());
		self::assertSame(['news'], $tags->getPresetTags());
		self::assertSame('Pick tags', $tags->getplaceholder());

		self::assertSame(
			'map-default',
			$this->interfaceOnNewField($field, 'map', MapInterface::class)->defaultView('map-default')->getDefaultView(),
		);
		self::assertSame('root', $this->interfaceOnNewField($field, 'body', WYSIWYGInterface::class)->getFolder());
		self::assertSame('Enabled', $this->interfaceOnNewField($field, 'enabled', ToggleInterface::class)->label('Enabled')->getLabel());
	}

	/**
	 * @template T of InterfaceInterface
	 * @param class-string<T> $interfaceClass
	 * @return T
	 */
	private function interfaceOnNewField(FieldInterface $field, string $name, string $interfaceClass): mixed
	{
		$type = $name === 'enabled' ? 'bool' : 'string';
		$next = $field->end()->field($name, $type);
		$next->extend(UiDefinitionPlugin::class)->interface($interfaceClass)->end();
		$interface = $next->getPlugin(UiDefinitionPlugin::class)?->getInterface();
		self::assertInstanceOf($interfaceClass, $interface);

		return $interface;
	}
}
