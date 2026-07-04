<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Persistence;

use ON\Data\Definition\Registry;
use ON\Data\ORM\Persistence\CommandBuffer;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\Support\Relation\TestCommand;

final class CommandBufferTest extends TestCase
{
	public function testAddAndGetAllPreserveCommandOrder(): void
	{
		$collection = (new Registry())->collection('users');
		$first = new TestCommand($collection);
		$second = new TestCommand($collection);
		$buffer = new CommandBuffer();

		$buffer->add($first);
		$buffer->add($second);

		self::assertSame([$first, $second], $buffer->getAll());
	}

	public function testClearEmptiesBuffer(): void
	{
		$buffer = new CommandBuffer();
		$buffer->add(new TestCommand((new Registry())->collection('users')));

		$buffer->clear();

		self::assertSame([], $buffer->getAll());
	}

	public function testIsEmptyReflectsState(): void
	{
		$buffer = new CommandBuffer();

		self::assertTrue($buffer->isEmpty());

		$buffer->add(new TestCommand((new Registry())->collection('users')));

		self::assertFalse($buffer->isEmpty());
	}
}
