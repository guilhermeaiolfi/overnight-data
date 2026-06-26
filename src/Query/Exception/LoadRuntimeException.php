<?php

declare(strict_types=1);

namespace ON\Data\Query\Exception;

use LogicException;
use ON\Data\Query\Relation\RelationRef;

final class LoadRuntimeException extends LogicException
{
	public static function activeBranchMissing(): self
	{
		return new self('LoadRuntime does not have an active relation branch.');
	}

	public static function activeBranchMetadataUnavailable(string $name): self
	{
		return new self(sprintf(
			'LoadRuntime cannot provide %s outside active loader registration.',
			$name,
		));
	}

	public static function nodeAlreadyRegistered(RelationRef $relation): self
	{
		return new self(sprintf(
			'Loader registered more than one parser node for relation "%s".',
			implode('.', $relation->getPath()),
		));
	}

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

	public static function parentNodeMissing(RelationRef $relation): self
	{
		return new self(sprintf(
			'LoadRuntime could not resolve the parent parser node for relation "%s".',
			implode('.', $relation->getPath()),
		));
	}

	public static function queryLocalRelationMissing(RelationRef $relation): self
	{
		return new self(sprintf(
			'LoadRuntime could not resolve a query-local relation reference for "%s".',
			implode('.', $relation->getPath()),
		));
	}

	public static function invalidReferenceValues(RelationRef $relation): self
	{
		return new self(sprintf(
			'LoadRuntime received invalid related-query reference values for relation "%s".',
			implode('.', $relation->getPath()),
		));
	}

	public static function nextPassNotAllowedDuringRegister(RelationRef $relation): self
	{
		return new self(sprintf(
			'Loader cannot schedule nextPass() during register() for relation "%s".',
			implode('.', $relation->getPath()),
		));
	}

	public static function multipleNextPasses(RelationRef $relation, string $method): self
	{
		return new self(sprintf(
			'Loader scheduled more than one next pass from "%s" for relation "%s".',
			$method,
			implode('.', $relation->getPath()),
		));
	}

	public static function invalidScheduledMethod(RelationRef $relation, string $method): self
	{
		return new self(sprintf(
			'Loader cannot schedule method "%s" for relation "%s".',
			$method,
			implode('.', $relation->getPath()),
		));
	}

	public static function scheduleBoundaryMissing(RelationRef $relation): self
	{
		return new self(sprintf(
			'LoadRuntime could not determine a scheduling boundary for relation "%s".',
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
}
