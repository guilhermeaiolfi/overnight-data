<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

enum WriteCommandKind
{
	case INSERT;
	case UPDATE;
	case DELETE;
}
