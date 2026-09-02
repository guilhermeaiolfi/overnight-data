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
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Plugin\UiDefinitionPlugin;
use ON\Data\Definition\Registry;
use PHPUnit\Framework\TestCase;

final class DisplayDefinitionTest extends TestCase
{
	public function testDisplayClassesKeepCurrentDefaultsAndConfiguration(): void
	{
		$field = (new Registry())->collection('post')->field('title', 'string');
		$field->extend(
			UiDefinitionPlugin::create()->display(RawDisplay::class)->type('raw')->end(),
		);
		$ui = $field->getPlugin(UiDefinitionPlugin::class);
		$raw = $ui?->getDisplay();

		self::assertInstanceOf(RawDisplay::class, $raw);
		self::assertSame('raw', $raw->getType());
		self::assertSame([], $raw->getOptions());
		self::assertSame($ui, $raw->end());

		$booleanField = $field->end()->field('active', 'bool');
		$booleanField->extend(
			UiDefinitionPlugin::create()
				->display(BooleanDisplay::class)
					->labelOn('On')
					->labelOff('Off')
					->iconOn('check')
					->iconOff('x')
					->colorOn('green')
					->colorOff('red')
					->end(),
		);
		$boolean = $booleanField->getPlugin(UiDefinitionPlugin::class)?->getDisplay();
		self::assertInstanceOf(BooleanDisplay::class, $boolean);
		self::assertSame('On', $boolean->getLabelOn());
		self::assertSame('Off', $boolean->getLabelOff());
		self::assertSame('check', $boolean->getIconOn());
		self::assertSame('x', $boolean->getIconOff());
		self::assertSame('green', $boolean->getColorOn());
		self::assertSame('red', $boolean->getColorOff());

		self::assertSame('long', $this->displayOnNewField($field, 'created_at', 'datetime', DatetimeDisplay::class)->getFormat());
		self::assertSame('short', $this->displayOnNewField($field, 'updated_at', 'datetime', DatetimeDisplay::class)->format('short')->getFormat());
		self::assertSame([], $this->displayOnNewField($field, 'attachment', 'file', FileDisplay::class)->getOptions());
		self::assertSame('blue', $this->displayOnNewField($field, 'formatted', 'string', FormattedDisplay::class)->color('blue')->getColor());
		self::assertSame('{{title}}', $this->displayOnNewField($field, 'json', 'string', FormattedJSONDisplay::class)->template('{{title}}')->getTemplate());
		self::assertTrue($this->displayOnNewField($field, 'icon', 'string', IconDisplay::class)->filled(true)->isFilled());
		self::assertTrue($this->displayOnNewField($field, 'avatar', 'string', ImageDisplay::class)->displayAsCircle(true)->shouldDisplayAsCircle());
		self::assertFalse($this->displayOnNewField($field, 'labels', 'string', LabelsDisplay::class)->formatEachLabel(false)->isFormatEachLabel());
		self::assertSame('{{title}}', $this->displayOnNewField($field, 'related', 'string', RelatedDisplay::class)->template('{{title}}')->getTemplate());
	}

	/**
	 * @template T of RawDisplay
	 * @param class-string<T> $displayClass
	 * @return T
	 */
	private function displayOnNewField(FieldInterface $field, string $name, string $type, string $displayClass): RawDisplay
	{
		$next = $field->end()->field($name, $type);
		$next->extend(UiDefinitionPlugin::create()->display($displayClass)->end());
		$display = $next->getPlugin(UiDefinitionPlugin::class)?->getDisplay();
		self::assertInstanceOf($displayClass, $display);

		return $display;
	}
}
