<?php

declare(strict_types=1);

namespace ON\Data\Definition\Field;

use InvalidArgumentException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Field\Generator\DatabaseGenerator;
use ON\Data\Definition\Field\Generator\FieldGeneratorInterface;
use ON\Data\Definition\Field\Generator\GeneratorDefinitionArgInterface;
use ON\Data\Definition\Field\Generator\PhpFieldGeneratorInterface;
use ON\Data\Definition\Field\Generator\When;
use ON\Data\Definition\Relation\RelationInterface;

trait SchemaTrait
{
	public static function schemaDefaults(): array
	{
		return [
			'nullable' => false,
			'hidden' => false,
			'unique' => false,
			'indexed' => false,
			'max_length' => 255,
			'numeric_precision' => 2,
			'default_value' => null,
			'data_type' => null,
			'comment' => null,
			'auto_increment' => false,
			'generator' => null,
			'filterable' => true,
		];
	}

	public function numericPrecision(int $numeric_precision): self
	{
		$this->set('numeric_precision', $numeric_precision);

		return $this;
	}

	public function getNumericPrecision(): int
	{
		return (int) $this->get('numeric_precision');
	}

	public function autoIncrement(bool $auto_increment): self
	{
		$this->set('auto_increment', $auto_increment);

		if ($auto_increment) {
			return $this->generator(DatabaseGenerator::class, null, When::INSERT);
		}

		$config = $this->getGenerator();
		if ($config !== null && is_a($config['class'], DatabaseGenerator::class, true)) {
			$this->set('generator', null);
		}

		return $this;
	}

	public function isAutoIncrement(): bool
	{
		if ((bool) $this->get('auto_increment')) {
			return true;
		}

		return $this->isDatabaseGenerated() && $this->isGeneratedWhen(When::INSERT);
	}

	/**
	 * Declare how this field's value is produced.
	 *
	 * Definitions stay array-backed: instances are flattened to class + arg.
	 * `$arg` may be a scalar, list (constructor args), or associative config array.
	 *
	 * @param class-string<FieldGeneratorInterface>|FieldGeneratorInterface $generator
	 *        Use {@see DatabaseGenerator} for DB-owned values (optional $arg = sequence name).
	 *        Use a {@see PhpFieldGeneratorInterface} for PHP values.
	 * @param int|null $when Bitmask of {@see When} flags; defaults to {@see When::INSERT}
	 */
	public function generator(
		string|FieldGeneratorInterface $generator,
		mixed $arg = null,
		?int $when = null,
	): self {
		if ($generator instanceof FieldGeneratorInterface) {
			$class = $generator::class;
			if ($arg === null) {
				if (! $generator instanceof GeneratorDefinitionArgInterface) {
					throw new InvalidArgumentException(sprintf(
						'Generator instance "%s" must implement %s (or pass an explicit $arg) so definitions can be stored as arrays.',
						$class,
						GeneratorDefinitionArgInterface::class,
					));
				}

				$arg = $generator->getDefinitionArg();
			}
		} else {
			$class = trim($generator);
		}

		if ($class === '' || ! is_a($class, FieldGeneratorInterface::class, true)) {
			throw new InvalidArgumentException(sprintf(
				'Field generator must be a class implementing %s.',
				FieldGeneratorInterface::class,
			));
		}

		$when ??= When::INSERT;
		if ($when <= 0) {
			throw new InvalidArgumentException('Field generator $when must be a positive When bitmask.');
		}

		$this->set('generator', [
			'class' => $class,
			'arg' => $arg,
			'when' => $when,
		]);
		$this->set('auto_increment', is_a($class, DatabaseGenerator::class, true));

		return $this;
	}

	/**
	 * @return array{class: class-string<FieldGeneratorInterface>, arg: mixed, when: int}|null
	 */
	public function getGenerator(): ?array
	{
		$config = $this->get('generator');
		if (! is_array($config)) {
			return null;
		}

		$class = $config['class'] ?? null;
		$when = $config['when'] ?? null;
		if (! is_string($class) || $class === '' || ! is_int($when) || $when <= 0) {
			return null;
		}

		/** @var class-string<FieldGeneratorInterface> $class */
		return [
			'class' => $class,
			'arg' => $config['arg'] ?? null,
			'when' => $when,
		];
	}

	public function hasGenerator(): bool
	{
		return $this->getGenerator() !== null;
	}

	public function isDatabaseGenerated(): bool
	{
		$config = $this->getGenerator();
		if ($config !== null) {
			return is_a($config['class'], DatabaseGenerator::class, true);
		}

		// Legacy definitions that only set auto_increment.
		return (bool) $this->get('auto_increment');
	}

	public function isGeneratedWhen(int $when): bool
	{
		$config = $this->getGenerator();
		if ($config !== null) {
			return When::includes($config['when'], $when);
		}

		return (bool) $this->get('auto_increment') && $when === When::INSERT;
	}

	public function getGeneratorSequence(): ?string
	{
		$config = $this->getGenerator();
		if ($config === null || ! is_a($config['class'], DatabaseGenerator::class, true)) {
			return null;
		}

		$arg = $config['arg'];
		if (is_string($arg) && $arg !== '') {
			return $arg;
		}

		if (is_array($arg) && isset($arg['sequence']) && is_string($arg['sequence']) && $arg['sequence'] !== '') {
			return $arg['sequence'];
		}

		return null;
	}

	public function isPrimaryKey(): bool
	{
		$parent = $this->getParent();

		return $parent instanceof CollectionInterface
			&& $parent->hasPrimaryKey()
			&& in_array($this->getName(), $parent->getPrimaryKey(), true);
	}

	public function filterable(bool $filterable = true): self
	{
		$this->set('filterable', $filterable);

		return $this;
	}

	public function isFilterable(): bool
	{
		return (bool) $this->get('filterable');
	}

	public function dataType(mixed $data_type): self
	{
		$this->set('data_type', $data_type);

		return $this;
	}

	public function getDataType(): mixed
	{
		return $this->get('data_type');
	}

	public function defaultValue(mixed $default_value): self
	{
		$this->set('default_value', $default_value);

		return $this;
	}

	public function getDefaultValue(): mixed
	{
		return $this->get('default_value');
	}

	public function maxLength(int $max_length): self
	{
		$this->set('max_length', $max_length);

		return $this;
	}

	public function getMaxLength(): int
	{
		return (int) $this->get('max_length');
	}

	public function nullable(bool $nullable): self
	{
		$this->set('nullable', $nullable);

		return $this;
	}

	public function isNullable(): bool
	{
		return (bool) $this->get('nullable');
	}

	public function hidden(bool $hidden): self
	{
		$this->set('hidden', $hidden);

		return $this;
	}

	public function isHidden(): bool
	{
		return (bool) $this->get('hidden');
	}

	public function unique(bool $unique): self
	{
		$this->set('unique', $unique);

		return $this;
	}

	public function isUnique(): bool
	{
		return (bool) $this->get('unique');
	}

	public function indexed(bool $indexed): self
	{
		$this->set('indexed', $indexed);

		return $this;
	}

	public function isIndexed(): bool
	{
		return (bool) $this->get('indexed');
	}

	public function comment(string $comment): self
	{
		$this->set('comment', $comment);

		return $this;
	}

	public function getComment(): ?string
	{
		$value = $this->get('comment');

		return is_string($value) ? $value : null;
	}

	public function end(): FieldInterface|RelationInterface
	{
		return $this->parent;
	}
}
