<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

final class SourcePostNestedDto
{
	public SourceAuthorDto $author;

	/** @var list<SourceAuthorDto> */
	public array $authors = [];

	public string $active = 'false';
}
