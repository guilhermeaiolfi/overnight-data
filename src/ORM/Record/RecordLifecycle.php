<?php

declare(strict_types=1);

namespace ON\Data\ORM\Record;

enum RecordLifecycle
{
	case NEW;
	case CLEAN;
	case DIRTY;
	case REMOVED;
}
