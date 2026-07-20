<?php

declare(strict_types=1);

namespace ON\Data\Definition\Field\Generator;

use InvalidArgumentException;
use ON\Data\Definition\Field\FieldInterface;
use ReflectionClass;
use ReflectionException;

/**
 * Resolves stored generator definitions into runtime instances.
 */
final class FieldGeneratorFactory
{
	/**
	 * @param class-string $class
	 */
	public function create(string $class, mixed $arg = null): FieldGeneratorInterface
	{
		if (! is_a($class, FieldGeneratorInterface::class, true)) {
			throw new InvalidArgumentException(sprintf(
				'Field generator "%s" must implement %s.',
				$class,
				FieldGeneratorInterface::class,
			));
		}

		if (is_a($class, DatabaseGenerator::class, true)) {
			return new DatabaseGenerator($this->normalizeDatabaseSequence($arg));
		}

		try {
			$reflection = new ReflectionClass($class);
		} catch (ReflectionException $exception) {
			throw new InvalidArgumentException(sprintf(
				'Field generator "%s" cannot be reflected.',
				$class,
			), 0, $exception);
		}

		if (! $reflection->isInstantiable()) {
			throw new InvalidArgumentException(sprintf(
				'Field generator "%s" is not instantiable.',
				$class,
			));
		}

		if ($arg === null) {
			return $reflection->newInstance();
		}

		if (is_array($arg) && array_is_list($arg)) {
			return $reflection->newInstanceArgs($arg);
		}

		return $reflection->newInstance($arg);
	}

	public function createForField(FieldInterface $field): ?FieldGeneratorInterface
	{
		$config = $field->getGenerator();
		if ($config === null) {
			return null;
		}

		return $this->create($config['class'], $config['arg']);
	}

	private function normalizeDatabaseSequence(mixed $arg): ?string
	{
		if (is_string($arg) && $arg !== '') {
			return $arg;
		}

		if (is_array($arg) && isset($arg['sequence']) && is_string($arg['sequence']) && $arg['sequence'] !== '') {
			return $arg['sequence'];
		}

		return null;
	}
}
