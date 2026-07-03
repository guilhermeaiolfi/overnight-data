<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use PHPUnit\Framework\TestCase;

final class RepresentationBindingTest extends TestCase
{
	public function testAddAndGetBindingByPath(): void
	{
		$binding = new RepresentationBinding();
		$fieldBinding = $this->fieldBinding('name');

		$binding->add($fieldBinding);

		self::assertTrue($binding->has('name'));
		self::assertSame($fieldBinding, $binding->get('name'));
	}

	public function testDuplicatePathThrows(): void
	{
		$binding = new RepresentationBinding();
		$binding->add($this->fieldBinding('name'));

		$this->expectException(StateException::class);
		$binding->add($this->fieldBinding('name'));
	}

	public function testWritableFilterWorks(): void
	{
		$binding = new RepresentationBinding();
		$writable = $this->fieldBinding('name');
		$binding->add($writable);
		$binding->add($this->fieldBinding('upperName', false));

		self::assertSame([$writable], $binding->getWritableBindings());
	}

	public function testReadOnlyFilterWorks(): void
	{
		$binding = new RepresentationBinding();
		$binding->add($this->fieldBinding('name'));
		$readOnly = $this->fieldBinding('upperName', false);
		$binding->add($readOnly);

		self::assertSame([$readOnly], $binding->getReadOnlyBindings());
	}

	public function testInsertionOrderIsPreserved(): void
	{
		$binding = new RepresentationBinding();
		$name = $this->fieldBinding('name');
		$email = $this->fieldBinding('email');

		$binding->add($name);
		$binding->add($email);

		self::assertSame([$name, $email], $binding->getAll());
	}

	public function testBindingIsReusableMappingShapeBeforeItIsAppliedToRepresentation(): void
	{
		$binding = new RepresentationBinding();
		$name = $this->fieldBinding('name');
		$binding->add($name);

		$rootBinding = $binding;
		$childTemplate = $binding;
		$relationItemTemplate = $binding;

		self::assertSame($name, $rootBinding->get('name'));
		self::assertSame($rootBinding, $childTemplate);
		self::assertSame($rootBinding, $relationItemTemplate);
	}

	private function fieldBinding(string $path, bool $writable = true): RepresentationFieldBinding
	{
		return new RepresentationFieldBinding($path, new RecordFieldRef($this->users(), $path), $writable);
	}

	private function users(): CollectionInterface
	{
		return (new Registry())->collection('users')->primaryKey('id')->field('id', 'int')->end();
	}
}
