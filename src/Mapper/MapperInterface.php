<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Mapper\Representation\RepresentationInterface;

interface MapperInterface
{
	public static function canMap(
		mixed $source,
		mixed $target,
		MappingContext $context,
	): bool;

	public function map(
		mixed $source,
		mixed $target,
		MappingContext $context,
	): mixed;

	/**
	 * @return array{
	 *     from?: class-string<RepresentationInterface>,
	 *     as?: class-string<RepresentationInterface>
	 * }
	 */
	public static function defaultRepresentations(): array;
}
