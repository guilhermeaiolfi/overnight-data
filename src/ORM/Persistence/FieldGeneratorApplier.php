<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\Definition\Field\Generator\FieldGeneratorFactory;
use ON\Data\Definition\Field\Generator\GenerationContext;
use ON\Data\Definition\Field\Generator\PhpFieldGeneratorInterface;
use ON\Data\Definition\Field\Generator\When;
use ON\Data\ORM\Record\RecordState;

/**
 * Applies PHP field generators onto record values before command planning.
 */
final class FieldGeneratorApplier
{
	public function __construct(
		private readonly FieldGeneratorFactory $factory = new FieldGeneratorFactory(),
	) {
	}

	public function apply(RecordState $record, int $when): void
	{
		$collection = $record->getCollection();
		$values = $record->getValues();

		foreach ($collection->getFields() as $field) {
			if (! $field->isGeneratedWhen($when) || $field->isDatabaseGenerated()) {
				continue;
			}

			$config = $field->getGenerator();
			if ($config === null) {
				continue;
			}

			$fieldName = $field->getName();
			if (
				$when === When::INSERT
				&& array_key_exists($fieldName, $values)
				&& $values[$fieldName] !== null
			) {
				continue;
			}

			$generator = $this->factory->create($config['class'], $config['arg']);
			if (! $generator instanceof PhpFieldGeneratorInterface) {
				continue;
			}

			$generated = $generator->generate(new GenerationContext(
				$collection,
				$field,
				$record,
				$when,
				$config['arg'],
			));

			$record->setValues([$fieldName => $generated]);
			$values[$fieldName] = $generated;
		}
	}
}
