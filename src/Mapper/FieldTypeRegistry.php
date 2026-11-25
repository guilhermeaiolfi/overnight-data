<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use BackedEnum;
use ON\Data\Mapper\Exception\InvalidMapperComponentException;
use ON\Data\Mapper\Field\BackedEnumFieldType;
use ON\Data\Mapper\Representation\RepresentationInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;

/** @internal Field types and codecs registered together for one mapper manager. */
final class FieldTypeRegistry
{
	/**
	 * @var array<string, class-string<FieldTypeInterface>>
	 */
	private array $fieldTypes = [];

	/**
	 * @var array<
	 *     class-string<RepresentationInterface>,
	 *     array<
	 *         class-string<FieldTypeInterface>,
	 *         class-string<FieldTypeCodecInterface>
	 *     >
	 * >
	 */
	private array $fieldTypeCodecs = [];

	/**
	 * @var array<
	 *     class-string<FieldTypeInterface>,
	 *     array<class-string<RepresentationInterface>, class-string<FieldTypeCodecInterface>|null>
	 * >
	 */
	private array $resolvedFieldTypeCodecs = [];

	/**
	 * @return array<string, class-string<FieldTypeInterface>>
	 */
	public function getRegisteredFieldTypes(): array
	{
		return $this->fieldTypes;
	}

	/**
	 * @return array<
	 *     class-string<RepresentationInterface>,
	 *     array<
	 *         class-string<FieldTypeInterface>,
	 *         class-string<FieldTypeCodecInterface>
	 *     >
	 * >
	 */
	public function getRegisteredFieldTypeCodecs(): array
	{
		return $this->fieldTypeCodecs;
	}

	/**
	 * @return class-string<FieldTypeInterface>|null
	 */
	public function resolveFieldType(LeafNodeResolutionInterface $field): ?string
	{
		$type = $field->getType();
		if ($type === null) {
			return null;
		}

		if (is_a($type, FieldTypeInterface::class, true)) {
			/** @var class-string<FieldTypeInterface> $type */
			return $type;
		}

		if (enum_exists($type) && is_a($type, BackedEnum::class, true)) {
			return BackedEnumFieldType::class;
		}

		return $this->fieldTypes[strtolower($type)] ?? null;
	}

	/**
	 * @param class-string<FieldTypeInterface> $fieldType
	 * @param class-string<RepresentationInterface> $representation
	 *
	 * @return class-string<FieldTypeCodecInterface>|null
	 */
	public function resolveFieldTypeCodec(string $fieldType, string $representation): ?string
	{
		if (isset($this->resolvedFieldTypeCodecs[$fieldType]) && array_key_exists($representation, $this->resolvedFieldTypeCodecs[$fieldType])) {
			return $this->resolvedFieldTypeCodecs[$fieldType][$representation];
		}

		$resolved = null;
		$current = $representation;

		do {
			$codec = $this->fieldTypeCodecs[$current][$fieldType] ?? null;
			if ($codec !== null) {
				$resolved = $codec;

				break;
			}

			$parent = get_parent_class($current);
			if (! is_string($parent) || ! is_a($parent, RepresentationInterface::class, true)) {
				break;
			}

			$current = $parent;
		} while (true);

		$this->resolvedFieldTypeCodecs[$fieldType][$representation] = $resolved;

		return $resolved;
	}

	/**
	 * @param class-string<FieldTypeInterface> $fieldType
	 */
	public function registerFieldType(string $fieldType, bool $overwrite): void
	{
		$names = $fieldType::getNames();
		if ($names === []) {
			throw new InvalidMapperComponentException(
				sprintf("FieldType '%s' must declare at least one registration name.", $fieldType),
			);
		}

		foreach ($names as $name) {
			if (! is_string($name) || $name === '') {
				throw new InvalidMapperComponentException(
					sprintf("FieldType '%s' must declare only non-empty string names.", $fieldType),
				);
			}
		}

		foreach ($names as $name) {
			$alias = strtolower($name);
			if ($overwrite || ! isset($this->fieldTypes[$alias])) {
				$this->fieldTypes[$alias] = $fieldType;
			}
		}

		$this->clearCache();
	}

	/**
	 * @param class-string<FieldTypeCodecInterface> $codec
	 */
	public function registerFieldTypeCodec(string $codec, bool $overwrite): void
	{
		$fieldType = $codec::getFieldType();
		if (! class_exists($fieldType) || ! is_a($fieldType, FieldTypeInterface::class, true)) {
			throw new InvalidMapperComponentException(
				sprintf("FieldType codec '%s' must reference a valid %s class.", $codec, FieldTypeInterface::class),
			);
		}

		$representation = $codec::getRepresentation();
		if (! class_exists($representation) || ! is_a($representation, RepresentationInterface::class, true)) {
			throw new InvalidMapperComponentException(
				sprintf("FieldType codec '%s' must reference a valid %s class.", $codec, RepresentationInterface::class),
			);
		}

		/** @var class-string<FieldTypeInterface> $fieldType */
		/** @var class-string<RepresentationInterface> $representation */
		if ($overwrite || ! isset($this->fieldTypeCodecs[$representation][$fieldType])) {
			$this->fieldTypeCodecs[$representation][$fieldType] = $codec;
		}
		$this->clearCache();
	}

	public function clearCache(): void
	{
		$this->resolvedFieldTypeCodecs = [];
	}
}
