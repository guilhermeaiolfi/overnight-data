<?php

declare(strict_types=1);

namespace ON\Data\Query\Exception;

use LogicException;
use ON\Data\Query\Relation\RelationRef;

final class LoadRuntimeException extends LogicException
{
	public static function nodeNotRegistered(RelationRef $relation): self
	{
		return new self(sprintf(
			'Loader did not register a parser node for relation "%s".',
			implode('.', $relation->getPath()),
		));
	}

	public static function parentBranchMissing(RelationRef $relation): self
	{
		return new self(sprintf(
			'LoadRuntime could not resolve the parent branch for relation "%s".',
			implode('.', $relation->getPath()),
		));
	}

	public static function queryNotConfigured(RelationRef $relation): self
	{
		return new self(sprintf(
			'Loader did not configure a result query for relation "%s".',
			implode('.', $relation->getPath()),
		));
	}

	public static function schemaMissing(): self
	{
		return new self(
			'Row assemble requires a compiled RepresentationSchema; SelectQuery must compile fetch schema before LoadRuntime assemble.',
		);
	}

	/**
	 * @param list<string> $relationPath
	 */
	public static function schemaNodeMissing(array $relationPath): self
	{
		$path = $relationPath === [] ? '(root)' : implode('.', $relationPath);

		return new self(sprintf(
			'Row assemble could not resolve RepresentationSchema node for relation path "%s".',
			$path,
		));
	}
}
