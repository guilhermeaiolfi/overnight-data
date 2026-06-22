<?php

declare(strict_types=1);

namespace Benchmarks\ON\Data\Mapper\Support;

use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Registry;

final class MappingDataset
{
	private DefinitionInterface $flatDefinition;

	public function __construct()
	{
		$this->flatDefinition = $this->createFlatDefinition();
	}

	public function flatDefinition(): DefinitionInterface
	{
		return $this->flatDefinition;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function createFlatArrayCollection(int $count): array
	{
		$rows = [];

		for ($index = 1; $index <= $count; $index++) {
			$rows[] = $this->createFlatArray($index);
		}

		return $rows;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function createFlatWireArrayCollection(int $count): array
	{
		$rows = [];

		for ($index = 1; $index <= $count; $index++) {
			$rows[] = $this->createFlatWireArray($index);
		}

		return $rows;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function createFlatStorageArrayCollection(int $count): array
	{
		$rows = [];

		for ($index = 1; $index <= $count; $index++) {
			$rows[] = $this->createFlatStorageArray($index);
		}

		return $rows;
	}

	/**
	 * @return list<FlatSourceDto>
	 */
	public function createFlatDtoCollection(int $count): array
	{
		$rows = [];

		for ($index = 1; $index <= $count; $index++) {
			$rows[] = $this->createFlatSourceDto($index);
		}

		return $rows;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function createNestedArrayCollection(int $count): array
	{
		$rows = [];

		for ($index = 1; $index <= $count; $index++) {
			$rows[] = $this->createNestedArray($index);
		}

		return $rows;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function createDottedNestedArrayCollection(int $count): array
	{
		$rows = [];

		for ($index = 1; $index <= $count; $index++) {
			$rows[] = $this->createDottedNestedArray($index);
		}

		return $rows;
	}

	private function createFlatDefinition(): DefinitionInterface
	{
		$registry = new Registry();
		$definition = $registry->collection('benchmark_rows');
		$definition->field('id', 'int');
		$definition->field('tenant_id', 'int');
		$definition->field('code', 'string');
		$definition->field('name', 'string');
		$definition->field('email', 'string');
		$definition->field('active', 'bool');
		$definition->field('verified', 'bool');
		$definition->field('user_score', 'float');
		$definition->field('balance', 'float');
		$definition->field('ratio', 'float');
		$definition->field('age', 'int');
		$definition->field('rank', 'int');
		$definition->field('state_label', 'string');
		$definition->field('slug', 'string');
		$definition->field('summary', 'string')->nullable(true);
		$definition->field('notes', 'string')->nullable(true);
		$definition->field('city', 'string');
		$definition->field('country', 'string');
		$definition->field('zip', 'string');
		$definition->field('phone', 'string')->nullable(true);
		$definition->field('created_at', 'datetime');
		$definition->field('updated_at', 'datetime');
		$definition->field('login_count', 'int');
		$definition->field('failure_count', 'int');
		$definition->field('is_premium', 'bool');
		$definition->field('is_archived', 'bool');
		$definition->field('credit_amount', 'float');
		$definition->field('debit_amount', 'float');
		$definition->field('category', 'string');
		$definition->field('secret_token', 'string');

		return $definition;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function createFlatArray(int $index): array
	{
		return [
			'id' => $index,
			'tenant_id' => 1000 + $index,
			'code' => sprintf('USR-%05d', $index),
			'name' => sprintf('User %05d', $index),
			'email' => sprintf('user%05d@example.test', $index),
			'active' => $index % 2 === 0,
			'verified' => $index % 3 === 0,
			'user_score' => $index / 10,
			'balance' => $index * 1.25,
			'ratio' => ($index % 100) / 100,
			'age' => 20 + ($index % 40),
			'rank' => $index % 1000,
			'state_label' => $index % 2 === 0 ? 'active' : 'paused',
			'slug' => sprintf('user-%05d', $index),
			'summary' => $index % 5 === 0 ? null : sprintf('Summary %05d', $index),
			'notes' => $index % 7 === 0 ? null : sprintf('Notes %05d', $index),
			'city' => 'Sao Paulo',
			'country' => 'BR',
			'zip' => sprintf('0100%04d', $index % 10000),
			'phone' => $index % 4 === 0 ? null : sprintf('+55-11-%04d-%04d', $index % 10000, ($index * 7) % 10000),
			'created_at' => sprintf('2026-06-%02d 10:15:00', (($index - 1) % 28) + 1),
			'updated_at' => sprintf('2026-07-%02d 17:45:00', (($index - 1) % 28) + 1),
			'login_count' => $index % 50,
			'failure_count' => $index % 6,
			'is_premium' => $index % 10 === 0,
			'is_archived' => $index % 17 === 0,
			'credit_amount' => $index * 2.5,
			'debit_amount' => $index * 1.1,
			'category' => 'benchmark',
			'secret_token' => sprintf('token-%05d', $index),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function createFlatWireArray(int $index): array
	{
		$array = $this->createFlatArray($index);
		$array['id'] = (string) $array['id'];
		$array['tenant_id'] = (string) $array['tenant_id'];
		$array['active'] = $array['active'] ? 'true' : 'false';
		$array['verified'] = $array['verified'] ? 'true' : 'false';
		$array['user_score'] = number_format((float) $array['user_score'], 1, '.', '');
		$array['balance'] = number_format((float) $array['balance'], 2, '.', '');
		$array['ratio'] = number_format((float) $array['ratio'], 2, '.', '');
		$array['age'] = (string) $array['age'];
		$array['rank'] = (string) $array['rank'];
		$array['login_count'] = (string) $array['login_count'];
		$array['failure_count'] = (string) $array['failure_count'];
		$array['is_premium'] = $array['is_premium'] ? 'true' : 'false';
		$array['is_archived'] = $array['is_archived'] ? 'true' : 'false';
		$array['credit_amount'] = number_format((float) $array['credit_amount'], 2, '.', '');
		$array['debit_amount'] = number_format((float) $array['debit_amount'], 2, '.', '');

		return $array;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function createFlatStorageArray(int $index): array
	{
		$array = $this->createFlatArray($index);
		$array['id'] = (string) $array['id'];
		$array['tenant_id'] = (string) $array['tenant_id'];
		$array['active'] = $array['active'] ? '1' : '0';
		$array['verified'] = $array['verified'] ? '1' : '0';
		$array['user_score'] = number_format((float) $array['user_score'], 1, '.', '');
		$array['balance'] = number_format((float) $array['balance'], 2, '.', '');
		$array['ratio'] = number_format((float) $array['ratio'], 2, '.', '');
		$array['age'] = (string) $array['age'];
		$array['rank'] = (string) $array['rank'];
		$array['login_count'] = (string) $array['login_count'];
		$array['failure_count'] = (string) $array['failure_count'];
		$array['is_premium'] = $array['is_premium'] ? '1' : '0';
		$array['is_archived'] = $array['is_archived'] ? '1' : '0';
		$array['credit_amount'] = number_format((float) $array['credit_amount'], 2, '.', '');
		$array['debit_amount'] = number_format((float) $array['debit_amount'], 2, '.', '');

		return $array;
	}

	private function createFlatSourceDto(int $index): FlatSourceDto
	{
		$array = $this->createFlatArray($index);
		$dto = new FlatSourceDto();
		$dto->id = $array['id'];
		$dto->tenantId = $array['tenant_id'];
		$dto->code = $array['code'];
		$dto->name = $array['name'];
		$dto->email = $array['email'];
		$dto->active = $array['active'];
		$dto->verified = $array['verified'];
		$dto->score = $array['user_score'];
		$dto->balance = $array['balance'];
		$dto->ratio = $array['ratio'];
		$dto->age = $array['age'];
		$dto->rank = $array['rank'];
		$dto->status = $array['state_label'];
		$dto->slug = $array['slug'];
		$dto->summary = $array['summary'];
		$dto->notes = $array['notes'];
		$dto->city = $array['city'];
		$dto->country = $array['country'];
		$dto->zip = $array['zip'];
		$dto->phone = $array['phone'];
		$dto->createdAt = $array['created_at'];
		$dto->updatedAt = $array['updated_at'];
		$dto->loginCount = $array['login_count'];
		$dto->failureCount = $array['failure_count'];
		$dto->premium = $array['is_premium'];
		$dto->archived = $array['is_archived'];
		$dto->credit = $array['credit_amount'];
		$dto->debit = $array['debit_amount'];
		$dto->category = $array['category'];
		$dto->secretToken = $array['secret_token'];

		return $dto;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function createNestedArray(int $index): array
	{
		return [
			'id' => $index,
			'tenantId' => 500 + $index,
			'title' => sprintf('Article %05d', $index),
			'slug' => sprintf('article-%05d', $index),
			'status' => $index % 2 === 0 ? 'published' : 'draft',
			'active' => $index % 2 === 0,
			'featured' => $index % 9 === 0,
			'price' => $index * 3.75,
			'rating' => ($index % 50) / 10,
			'priority' => $index % 20,
			'revision' => 1 + ($index % 8),
			'language' => 'pt-BR',
			'timezone' => 'America/Sao_Paulo',
			'owner' => 'bench-owner',
			'editor' => 'bench-editor',
			'summary' => $index % 5 === 0 ? null : sprintf('Nested summary %05d', $index),
			'excerpt' => $index % 6 === 0 ? null : sprintf('Nested excerpt %05d', $index),
			'createdAt' => sprintf('2026-06-%02dT08:00:00-03:00', (($index - 1) % 28) + 1),
			'updatedAt' => sprintf('2026-07-%02dT11:30:00-03:00', (($index - 1) % 28) + 1),
			'publishedAt' => sprintf('2026-08-%02dT14:45:00-03:00', (($index - 1) % 28) + 1),
			'child' => [
				'id' => $index * 10,
				'display_name' => sprintf('Child %05d', $index),
				'active' => $index % 2 === 0,
				'score' => $index / 100,
			],
			'children' => [
				[
					'id' => $index * 10 + 1,
					'display_name' => sprintf('Child %05d-A', $index),
					'active' => true,
					'score' => $index / 100,
				],
				[
					'id' => $index * 10 + 2,
					'display_name' => sprintf('Child %05d-B', $index),
					'active' => false,
					'score' => $index / 100 + 0.1,
				],
				[
					'id' => $index * 10 + 3,
					'display_name' => sprintf('Child %05d-C', $index),
					'active' => true,
					'score' => $index / 100 + 0.2,
				],
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function createDottedNestedArray(int $index): array
	{
		return [
			'id' => $index,
			'tenantId' => 500 + $index,
			'title' => sprintf('Article %05d', $index),
			'slug' => sprintf('article-%05d', $index),
			'status' => $index % 2 === 0 ? 'published' : 'draft',
			'active' => $index % 2 === 0,
			'featured' => $index % 9 === 0,
			'price' => $index * 3.75,
			'rating' => ($index % 50) / 10,
			'priority' => $index % 20,
			'revision' => 1 + ($index % 8),
			'language' => 'pt-BR',
			'timezone' => 'America/Sao_Paulo',
			'owner' => 'bench-owner',
			'editor' => 'bench-editor',
			'summary' => $index % 5 === 0 ? null : sprintf('Nested summary %05d', $index),
			'excerpt' => $index % 6 === 0 ? null : sprintf('Nested excerpt %05d', $index),
			'createdAt' => sprintf('2026-06-%02dT08:00:00-03:00', (($index - 1) % 28) + 1),
			'updatedAt' => sprintf('2026-07-%02dT11:30:00-03:00', (($index - 1) % 28) + 1),
			'publishedAt' => sprintf('2026-08-%02dT14:45:00-03:00', (($index - 1) % 28) + 1),
			'child.id' => $index * 10,
			'child.display_name' => sprintf('Child %05d', $index),
			'child.active' => $index % 2 === 0,
			'child.score' => $index / 100,
			'children.0.id' => $index * 10 + 1,
			'children.0.display_name' => sprintf('Child %05d-A', $index),
			'children.0.active' => true,
			'children.0.score' => $index / 100,
			'children.1.id' => $index * 10 + 2,
			'children.1.display_name' => sprintf('Child %05d-B', $index),
			'children.1.active' => false,
			'children.1.score' => $index / 100 + 0.1,
			'children.2.id' => $index * 10 + 3,
			'children.2.display_name' => sprintf('Child %05d-C', $index),
			'children.2.active' => true,
			'children.2.score' => $index / 100 + 0.2,
		];
	}
}
