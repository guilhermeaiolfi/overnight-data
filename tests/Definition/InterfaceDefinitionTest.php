<?php

declare(strict_types=1);

namespace Tests\ON\Data\Definition;

use ON\Data\Definition\Interface\ManyToManyInterface;
use ON\Data\Definition\Interface\MapInterface;
use ON\Data\Definition\Interface\TagsInterface;
use ON\Data\Definition\Interface\ToggleInterface;
use ON\Data\Definition\Interface\WYSIWYGInterface;
use ON\Data\Definition\Registry;
use PHPUnit\Framework\TestCase;

final class InterfaceDefinitionTest extends TestCase
{
	public function testInterfaceClassesKeepCurrentDefaultsAndOptions(): void
	{
		$field = (new Registry())->collection('post')->field('title', 'string');

		$many = $field->interface(ManyToManyInterface::class);
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
		self::assertSame($field, $many->end());

		$tags = $field->end()->field('tags', 'string')->interface(TagsInterface::class);
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

		self::assertSame('map-default', $field->end()->field('map', 'string')->interface(MapInterface::class)->defaultView('map-default')->getDefaultView());
		self::assertSame('root', $field->end()->field('body', 'string')->interface(WYSIWYGInterface::class)->getFolder());
		self::assertSame('Enabled', $field->end()->field('enabled', 'bool')->interface(ToggleInterface::class)->label('Enabled')->getLabel());
	}
}
