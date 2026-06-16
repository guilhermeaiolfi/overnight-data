<?php

declare(strict_types=1);

namespace ON\Data\Definition;

use ON\Data\Definition\Metadata\MetadataMap;

trait MetadataTrait
{
	protected ?MetadataMap $metadataMap = null;

	protected function getMetadataMap(): MetadataMap
	{
		if (! isset($this->items['metadata']) || ! is_array($this->items['metadata'])) {
			$this->items['metadata'] = [];
		}

		$metadata = &$this->items['metadata'];

		return $this->metadataMap ??= new MetadataMap($metadata);
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
