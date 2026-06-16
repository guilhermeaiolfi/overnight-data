<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Attribute\MapFrom;

class UserInputDto extends BasePublicDto
{
	public string $name = 'Anonymous';

	public ?string $nickname = null;

	public int $age;

	public bool $active = false;

	#[MapFrom('user_score')]
	public float $score = 0.0;

	private string $privateNote = 'secret';

	protected string $protectedNote = 'hidden';

	public static string $table = 'users';
}
