<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\State;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
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

	public function testApplyToRecordStateReturnsNewBindingWithoutMutatingTemplate(): void
	{
		$users = $this->users();
		$template = new RepresentationBinding();
		$templateName = new RepresentationFieldBinding('name', RecordFieldRef::template($users, 'name'));
		$templateUpper = new RepresentationFieldBinding('upperName', RecordFieldRef::template($users, 'name'), false);
		$template->add($templateName);
		$template->add($templateUpper);
		$state = RecordState::new($users, ['name' => 'A1']);

		$applied = $template->applyToRecordState($state);

		self::assertNotSame($template, $applied);
		self::assertSame($templateName, $template->get('name'));
		self::assertTrue($template->get('name')->getField()->isTemplate());
		self::assertSame($state, $applied->get('name')->getField()->getState());
		self::assertSame($state, $applied->get('upperName')->getField()->getState());
		self::assertSame('name', $applied->get('name')->getPath());
		self::assertSame('upperName', $applied->get('upperName')->getPath());
		self::assertTrue($applied->get('name')->isWritable());
		self::assertTrue($applied->get('upperName')->isReadOnly());
	}

	public function testApplyToRecordStateWithFieldFromAnotherCollectionThrows(): void
	{
		$template = new RepresentationBinding();
		$template->add(new RepresentationFieldBinding('title', RecordFieldRef::template($this->posts(), 'title')));

		$this->expectException(StateException::class);
		$template->applyToRecordState(RecordState::new($this->users()));
	}

	public function testApplyToRecordStateWithAlreadyKeyedFieldThrows(): void
	{
		$users = $this->users();
		$template = new RepresentationBinding();
		$template->add(new RepresentationFieldBinding('name', RecordFieldRef::forKey($users->getKey(10), 'name')));

		$this->expectException(StateException::class);
		$template->applyToRecordState(RecordState::new($users));
	}

	public function testApplyToRecordStateWithAlreadyStateTargetedFieldThrows(): void
	{
		$users = $this->users();
		$template = new RepresentationBinding();
		$template->add(new RepresentationFieldBinding('name', RecordFieldRef::forState(RecordState::new($users), 'name')));

		$this->expectException(StateException::class);
		$template->applyToRecordState(RecordState::new($users));
	}

	public function testTwoApplicationsToTwoNewStatesProduceDifferentRecordHashes(): void
	{
		$users = $this->users();
		$template = new RepresentationBinding();
		$template->add(new RepresentationFieldBinding('name', RecordFieldRef::template($users, 'name')));

		$first = $template->applyToRecordState(RecordState::new($users, ['name' => 'A1']));
		$second = $template->applyToRecordState(RecordState::new($users, ['name' => 'A2']));

		self::assertNotSame(
			$first->get('name')->getField()->getRecordHash(),
			$second->get('name')->getField()->getRecordHash()
		);
	}

	private function fieldBinding(string $path, bool $writable = true): RepresentationFieldBinding
	{
		return new RepresentationFieldBinding($path, new RecordFieldRef($this->users(), $path), $writable);
	}

	private function users(): CollectionInterface
	{
		return (new Registry())
			->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
	}

	private function posts(): CollectionInterface
	{
		return (new Registry())
			->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end();
	}
}
