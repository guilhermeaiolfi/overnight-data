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

		$raw = new RawDisplay($field);
		$raw->type('raw');
		self::assertSame('raw', $raw->getType());
		self::assertSame([], $raw->getOptions());
		self::assertSame($field, $raw->end());

		$boolean = (new BooleanDisplay($field))
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

		self::assertSame('long', (new DatetimeDisplay($field))->getFormat());
		self::assertSame('short', (new DatetimeDisplay($field))->format('short')->getFormat());
		self::assertSame([], (new FileDisplay($field))->getOptions());
		self::assertSame('blue', (new FormattedDisplay($field))->color('blue')->getColor());
		self::assertSame('{{title}}', (new FormattedJSONDisplay($field))->template('{{title}}')->getTemplate());
		self::assertTrue((new IconDisplay($field))->filled(true)->isFilled());
		self::assertTrue((new ImageDisplay($field))->displayAsCircle(true)->shouldDisplayAsCircle());
		self::assertFalse((new LabelsDisplay($field))->formatEachLabel(false)->isFormatEachLabel());
		self::assertSame('{{title}}', (new RelatedDisplay($field))->template('{{title}}')->getTemplate());
	}
}
