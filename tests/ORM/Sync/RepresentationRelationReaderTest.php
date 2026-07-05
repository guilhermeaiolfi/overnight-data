<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordRelationRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use ON\Data\ORM\Sync\RepresentationRelationReader;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\ORM\Support\OrmFixture;

final class RepresentationRelationReaderTest extends TestCase
{
	use OrmFixture;

	public function testManyRelationPathIsNullReturnsEmptyList(): void
	{
		$representation = $this->representation(['posts' => null]);
		$binding = $this->postsRelationBinding();

		self::assertSame([], $this->reader()->readItems($representation, $binding, $this->syncError(...)));
	}

	public function testManyRelationPathIsIterableOfObjectsReturnsList(): void
	{
		$item = new stdClass();
		$representation = $this->representation(['posts' => [$item]]);
		$binding = $this->postsRelationBinding();

		self::assertSame([$item], $this->reader()->readItems($representation, $binding, $this->syncError(...)));
	}

	public function testManyRelationPathIsNonIterableThrowsCallerException(): void
	{
		$representation = $this->representation(['posts' => 'bad']);
		$binding = $this->postsRelationBinding();

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('posts');
		$this->expectExceptionMessage('iterable');

		$this->reader()->readItems($representation, $binding, $this->syncError(...));
	}

	public function testManyRelationPathContainsNonObjectItemThrowsCallerException(): void
	{
		$representation = $this->representation(['posts' => ['bad']]);
		$binding = $this->postsRelationBinding();

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('posts');
		$this->expectExceptionMessage('only contain objects');

		$this->reader()->readItems($representation, $binding, $this->syncError(...));
	}

	public function testSingleRelationPathIsNullReturnsNull(): void
	{
		$representation = $this->representation(['profile' => null]);
		$binding = $this->profileRelationBinding();

		self::assertNull($this->reader()->readTarget($representation, $binding, $this->syncError(...)));
	}

	public function testSingleRelationPathIsObjectReturnsObject(): void
	{
		$target = new stdClass();
		$representation = $this->representation(['profile' => $target]);
		$binding = $this->profileRelationBinding();

		self::assertSame($target, $this->reader()->readTarget($representation, $binding, $this->syncError(...)));
	}

	public function testSingleRelationPathIsScalarThrowsCallerException(): void
	{
		$representation = $this->representation(['profile' => 123]);
		$binding = $this->profileRelationBinding();

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('profile');
		$this->expectExceptionMessage('object value or null');

		$this->reader()->readTarget($representation, $binding, $this->syncError(...));
	}

	public function testSingleRelationPathIsArrayThrowsCallerException(): void
	{
		$representation = $this->representation(['profile' => []]);
		$binding = $this->profileRelationBinding();

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('profile');
		$this->expectExceptionMessage('object value or null');

		$this->reader()->readTarget($representation, $binding, $this->syncError(...));
	}

	public function testSingleRelationPathIsIterableNonObjectThrowsCallerException(): void
	{
		$representation = $this->representation(['profile' => ['bad']]);
		$binding = $this->profileRelationBinding();

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('profile');
		$this->expectExceptionMessage('object value or null');

		$this->reader()->readTarget($representation, $binding, $this->syncError(...));
	}

	public function testCallerCanTransformMessageForGraphAdoption(): void
	{
		$representation = $this->representation(['posts' => 'bad']);
		$binding = $this->postsRelationBinding();

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('posts');
		$this->expectExceptionMessage('iterable');
		$this->expectExceptionMessage('during graph adoption');

		$this->reader()->readItems($representation, $binding, $this->adoptionError(...));
	}

	private function reader(): RepresentationRelationReader
	{
		return new RepresentationRelationReader();
	}

	/**
	 * @param non-empty-string $message
	 */
	private function syncError(string $message): SyncException
	{
		return new SyncException($message);
	}

	/**
	 * @param non-empty-string $message
	 */
	private function adoptionError(string $message): StateException
	{
		return new StateException(rtrim($message, '.') . ' during graph adoption.');
	}

	private function postsRelationBinding(): RepresentationRelationBinding
	{
		return new RepresentationRelationBinding(
			'posts',
			RecordRelationRef::forState(RecordState::new($this->users()), 'posts'),
			RepresentationRelationCardinality::MANY,
			$this->postBinding()
		);
	}

	private function profileRelationBinding(): RepresentationRelationBinding
	{
		return new RepresentationRelationBinding(
			'profile',
			RecordRelationRef::forState(RecordState::new($this->users()), 'profile'),
			RepresentationRelationCardinality::ONE,
			$this->profileBinding()
		);
	}
}
