<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

final class ReadonlyPromotedPostDto
{
	public readonly int $id;

	public readonly ReadonlyPromotedAuthorDto $author;

	/**
	 * @var list<ReadonlyPromotedAuthorDto>
	 */
	public readonly array $authors;

	/**
	 * @param list<ReadonlyPromotedAuthorDto> $authors
	 */
	public function __construct(
		int $id,
		ReadonlyPromotedAuthorDto $author,
		array $authors = [],
	) {
		$this->id = $id;
		$this->author = $author;
		$this->authors = $authors;
	}
}
