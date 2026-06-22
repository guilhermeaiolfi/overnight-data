<?php

declare(strict_types=1);

namespace Benchmarks\ON\Data\Mapper\Support;

use ON\Data\Mapper\Attribute\Hidden;
use ON\Data\Mapper\Attribute\MapTo;

final class FlatSourceDto
{
	public int $id;

	#[MapTo('tenant_id')]
	public int $tenantId;

	public string $code;
	public string $name;
	public string $email;
	public bool $active;
	public bool $verified;

	#[MapTo('user_score')]
	public float $score;

	public float $balance;
	public float $ratio;
	public int $age;
	public int $rank;

	#[MapTo('state_label')]
	public string $status;

	public string $slug;
	public ?string $summary = null;
	public ?string $notes = null;
	public string $city;
	public string $country;
	public string $zip;
	public ?string $phone = null;

	#[MapTo('created_at')]
	public string $createdAt;

	#[MapTo('updated_at')]
	public string $updatedAt;

	#[MapTo('login_count')]
	public int $loginCount;

	#[MapTo('failure_count')]
	public int $failureCount;

	#[MapTo('is_premium')]
	public bool $premium;

	#[MapTo('is_archived')]
	public bool $archived;

	#[MapTo('credit_amount')]
	public float $credit;

	#[MapTo('debit_amount')]
	public float $debit;

	public string $category;

	#[Hidden]
	#[MapTo('secret_token')]
	public string $secretToken;
}
