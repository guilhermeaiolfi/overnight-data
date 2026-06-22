<?php

declare(strict_types=1);

namespace Benchmarks\ON\Data\Mapper\Support;

use ON\Data\Mapper\Attribute\MapFrom;

final class FlatTargetDto
{
	public int $id;

	#[MapFrom('tenant_id')]
	public int $tenantId;

	public string $code;
	public string $name;
	public string $email;
	public bool $active;
	public bool $verified;

	#[MapFrom('user_score')]
	public float $score;

	public float $balance;
	public float $ratio;
	public int $age;
	public int $rank;

	#[MapFrom('state_label')]
	public string $status;

	public string $slug;
	public ?string $summary = null;
	public ?string $notes = null;
	public string $city;
	public string $country;
	public string $zip;
	public ?string $phone = null;

	#[MapFrom('created_at')]
	public string $createdAt;

	#[MapFrom('updated_at')]
	public string $updatedAt;

	#[MapFrom('login_count')]
	public int $loginCount;

	#[MapFrom('failure_count')]
	public int $failureCount;

	#[MapFrom('is_premium')]
	public bool $premium;

	#[MapFrom('is_archived')]
	public bool $archived;

	#[MapFrom('credit_amount')]
	public float $credit;

	#[MapFrom('debit_amount')]
	public float $debit;

	public string $category;

	#[MapFrom('secret_token')]
	public string $secretToken;
}
