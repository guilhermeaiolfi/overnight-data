<?php

declare(strict_types=1);

namespace ON\Data\ORM\History;

enum FlushChangeOperation: string
{
	case Insert = 'insert';
	case Update = 'update';
	case Delete = 'delete';
	case Custom = 'custom';
}
