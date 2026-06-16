<?php

declare(strict_types=1);

namespace ON\Data\Definition;

use ON\Data\Definition\Metadata\MetadataMap;

trait MetadataTrait
{
	protected ?MetadataMap $metadataMap = null;

	protected function getMetadataMap(): MetadataMap
	{
		return $this->metadataMap ??= new MetadataMap();
	}

	public function metadata(string $key, mixed $value = null): mixed
	{
		if ($value === null) {
			return $this->getMetadataMap()->get($key);
		}
		$this->getMetadataMap()->set($key, $value);

		return $this;
	}
}
