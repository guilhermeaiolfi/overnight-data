<?php

declare(strict_types=1);

namespace ON\Data\Database\Sql;

use ON\Data\Database\DatabasePlatformInterface;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\Join;
use ON\Data\Query\QueryFunction\CompiledExpression;
use ON\Data\Query\QueryFunction\FunctionCompilationContextInterface;

/**
 * @internal
 */
final class SqlFunctionCompilationContext implements FunctionCompilationContextInterface
{
	public function __construct(
		private readonly SqlQueryCompiler $translator,
		private readonly SqlTranslationContext $translationContext,
		private readonly DatabasePlatformInterface $platform,
		private readonly ?Join $joinContext,
	) {
	}

	public function platform(): DatabasePlatformInterface
	{
		return $this->platform;
	}

	public function compile(ValueExpressionInterface $expression): CompiledExpression
	{
		$fragment = $this->translator->translateExpressionForFunction(
			$expression,
			$this->translationContext,
			$this->joinContext,
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
}
