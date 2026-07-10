<?php

declare(strict_types=1);

namespace ON\Data\Database\Cycle;

use Cycle\Database\Injection\Parameter;
use Cycle\Database\Injection\ParameterInterface;
use ON\Data\Database\DatabasePlatformInterface;
use ON\Data\Query\Expression\FunctionCallExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\Join;
use ON\Data\Query\QueryFunction\CompiledExpression;
use ON\Data\Query\QueryFunction\FunctionCompilationContextInterface;
use ON\Data\Query\QueryFunction\FunctionCompilationException;

/**
 * @internal
 */
final class CycleFunctionCompilationContext implements FunctionCompilationContextInterface
{
	public function __construct(
		private readonly CycleQueryTranslator $translator,
		private readonly CycleTranslationContext $translationContext,
		private readonly DatabasePlatformInterface $platform,
		private readonly ?Join $joinContext,
		private readonly CycleFunctionCompilationStack $stack,
	) {
	}

	public function platform(): DatabasePlatformInterface
	{
		return $this->platform;
	}

	public function compile(ValueExpressionInterface $expression): CompiledExpression
	{
		if ($expression instanceof FunctionCallExpression && $this->stack->contains($expression)) {
			throw FunctionCompilationException::recursion($expression->getFunction());
		}

		$fragment = $this->translator->translateExpressionForFunction(
			$expression,
			$this->translationContext,
			$this->joinContext,
			$this->stack,
		);

		return new CompiledExpression(
			$fragment->sql(),
			array_values($fragment->parameters()),
		);
	}

	public function sql(string $sql, array $parameters = []): CompiledExpression
	{
		return new CompiledExpression($sql, array_values($parameters));
	}

	public function quoteIdentifier(string $identifier): string
	{
		return $this->translator->quoteIdentifierForFunction($identifier);
	}

	/**
	 * @param list<mixed> $parameters
	 * @return list<ParameterInterface>
	 */
	public static function adaptParameters(array $parameters): array
	{
		$adapted = [];

		foreach ($parameters as $parameter) {
			$adapted[] = $parameter instanceof ParameterInterface
				? $parameter
				: new Parameter($parameter);
		}

		return $adapted;
	}
}
