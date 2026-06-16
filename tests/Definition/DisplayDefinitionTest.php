<?php

declare(strict_types=1);

namespace Tests\ON\Data\Definition;

use ON\Data\Definition\Display\BooleanDisplay;
use ON\Data\Definition\Display\DatetimeDisplay;
use ON\Data\Definition\Display\FileDisplay;
use ON\Data\Definition\Display\FormattedDisplay;
use ON\Data\Definition\Display\FormattedJSONDisplay;
use ON\Data\Definition\Display\IconDisplay;
use ON\Data\Definition\Display\ImageDisplay;
use ON\Data\Definition\Display\LabelsDisplay;
use ON\Data\Definition\Display\RawDisplay;
use ON\Data\Definition\Display\RelatedDisplay;
use ON\Data\Definition\Registry;
use PHPUnit\Framework\TestCase;

final class DisplayDefinitionTest extends TestCase
{
	public function testDisplayClassesKeepCurrentDefaultsAndConfiguration(): void
	{
		$field = (new Registry())->collection('post')->field('title', 'string');

		$raw = $field->display(RawDisplay::class);
		$raw->type('raw');
		self::assertSame('raw', $raw->getType());
		self::assertSame([], $raw->getOptions());
		self::assertSame($field, $raw->end());

		$boolean = $field->end()->field('active', 'bool')->display(BooleanDisplay::class)
			->labelOn('On')
			->labelOff('Off')
			->iconOn('check')
			->iconOff('x')
			->colorOn('green')
			->colorOff('red');
		self::assertSame('On', $boolean->getLabelOn());
		self::assertSame('Off', $boolean->getLabelOff());
		self::assertSame('check', $boolean->getIconOn());
		self::assertSame('x', $boolean->getIconOff());
		self::assertSame('green', $boolean->getColorOn());
		self::assertSame('red', $boolean->getColorOff());

		self::assertSame('long', $field->end()->field('created_at', 'datetime')->display(DatetimeDisplay::class)->getFormat());
		self::assertSame('short', $field->end()->field('updated_at', 'datetime')->display(DatetimeDisplay::class)->format('short')->getFormat());
		self::assertSame([], $field->end()->field('attachment', 'file')->display(FileDisplay::class)->getOptions());
		self::assertSame('blue', $field->end()->field('formatted', 'string')->display(FormattedDisplay::class)->color('blue')->getColor());
		self::assertSame('{{title}}', $field->end()->field('json', 'string')->display(FormattedJSONDisplay::class)->template('{{title}}')->getTemplate());
		self::assertTrue($field->end()->field('icon', 'string')->display(IconDisplay::class)->filled(true)->isFilled());
		self::assertTrue($field->end()->field('avatar', 'string')->display(ImageDisplay::class)->displayAsCircle(true)->shouldDisplayAsCircle());
		self::assertFalse($field->end()->field('labels', 'string')->display(LabelsDisplay::class)->formatEachLabel(false)->isFormatEachLabel());
		self::assertSame('{{title}}', $field->end()->field('related', 'string')->display(RelatedDisplay::class)->template('{{title}}')->getTemplate());
	}
}
