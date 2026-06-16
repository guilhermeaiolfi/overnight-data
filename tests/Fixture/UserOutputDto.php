<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Attribute\Hidden;
use ON\Data\Mapper\Attribute\MapTo;

class UserOutputDto extends BasePublicDto
{
	#[MapTo('full_name')]
	public string $name;

	public ?string $nickname = null;

	public int $age;

	#[Hidden]
	public string $password = 'secret';

	public bool $active = false;

	public float $score = 0.0;

	public MixedValueObject $profile;

	private string $privateNote = 'secret';

	protected string $protectedNote = 'hidden';

	public static string $table = 'users';
}
