<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query;

use ON\Data\Definition\Registry;
use ON\Data\Query\Expression\FunctionCallExpression;
use ON\Data\Query\Expression\LiteralExpression;
use ON\Data\Query\QueryFunction\FunctionArgumentException;
use ON\Data\Query\QueryFunction\FunctionArguments;
use ON\Data\Query\QueryFunction\InvalidQueryFunctionException;
use ON\Data\Query\QueryFunction\QueryFunctionInterface;
use ON\Data\Query\QueryFunction\Standard\Temporal\Year;
use function ON\Data\Query\query;
use function ON\Data\Query\x;
use PHPUnit\Framework\TestCase;
use stdClass;

final class FunctionCallExpressionTest extends TestCase
{
	public function testCallCreatesFunctionCallExpressionWithNormalizedArguments(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));
		$expression = x()->fn()->call(Year::class, $users->createdAt, 'extra');

		self::assertInstanceOf(FunctionCallExpression::class, $expression);
		self::assertSame(Year::class, $expression->getFunction());
		self::assertSame($users->createdAt, $expression->getArguments()[0]);
		self::assertInstanceOf(LiteralExpression::class, $expression->getArguments()[1]);
		self::assertSame('extra', $expression->getArguments()[1]->getValue());
	}

	public function testCallRejectsInvalidFunctionClasses(): void
	{
		$this->expectException(InvalidQueryFunctionException::class);
		x()->fn()->call(stdClass::class, 1);
	}

	public function testCallRejectsMissingClasses(): void
	{
		$this->expectException(InvalidQueryFunctionException::class);
		x()->fn()->call('Tests\\ON\\Data\\Query\\MissingFunctionClass');
	}

	public function testCallRejectsClassesWithRequiredConstructorArgs(): void
	{
		$this->expectException(InvalidQueryFunctionException::class);
		x()->fn()->call(FunctionWithRequiredConstructor::class);
	}

	public function testBindToRebindsNestedArguments(): void
	{
		$registry = $this->makeRegistry();
		$users = query($registry->getCollection('users'));
		$posts = query($registry->getCollection('posts'));
		$expression = x()->fn()->call(Year::class, $users->createdAt);
		$bound = $expression->bindTo($posts, from: $users);

		self::assertNotSame($expression, $bound);
		self::assertSame(['createdAt'], $bound->getArguments()[0]->getPath());
		self::assertSame($posts, $bound->getArguments()[0]->getSource());
	}

	public function testFunctionArgumentsLiteralRequiresLiteralExpression(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));
		$arguments = new FunctionArguments([$users->createdAt, x()->literal(2024)]);

		self::assertSame(2, $arguments->count());
		self::assertTrue($arguments->has(0));
		self::assertSame(2024, $arguments->literal(1));

		$this->expectException(FunctionArgumentException::class);
		$arguments->literal(0);
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();
		$users = $registry->collection('users');
		$users->field('id', 'int');
		$users->field('createdAt', 'datetime');
		$users->primaryKey('id');

		$posts = $registry->collection('posts');
		$posts->field('id', 'int');
		$posts->field('createdAt', 'datetime');
		$posts->primaryKey('id');

		return $registry;
	}
}

final class FunctionWithRequiredConstructor implements QueryFunctionInterface
{
	public function __construct(private readonly string $name)
	{
	}

	public function compile(
		\ON\Data\Query\QueryFunction\FunctionCompilationContextInterface $context,
		FunctionArguments $arguments,
	): \ON\Data\Query\QueryFunction\CompiledExpression {
		return $context->sql('1');
	}
}
