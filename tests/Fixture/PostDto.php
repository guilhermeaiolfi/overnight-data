<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Attribute\MapFrom;

final class PostDto
{
	public int $id;

	public AuthorDto $author;

	/** @var list<AuthorDto> */
	public array $authors = [];

	#[MapFrom('writer')]
	public ?AuthorDto $writer = null;
}
