<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

enum ColumnRole: string
{
	case Public = 'public';
	case Identity = 'identity';
	case JoinKey = 'join-key';
}
