<?php

declare(strict_types=1);

namespace ON\Data;

use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\ORM\History\FlushHistory;
use ON\Data\ORM\Persistence\CommandExecutorInterface;
use ON\Data\ORM\Session;
use ON\Data\Query\DerivedSelectQuery;
use ON\Data\Query\SelectQuery;
use ON\Data\Schema\RepresentationSchema;

final class DataRuntime
{
	private readonly ConversionGateway $gateway;

	public function __construct(
		private readonly QueryExecutorInterface $queryExecutor,
		private readonly CommandExecutorInterface $commandExecutor,
		?ConversionGateway $gateway = null,
	) {
		$this->gateway = $gateway ?? ConversionGateway::createDefault();
	}

	public function query(CollectionInterface|DerivedSelectQuery|RepresentationSchema $source): SelectQuery
	{
		if ($source instanceof RepresentationSchema) {
			return SelectQuery::fromSchema($source, $this->queryExecutor, $this->gateway);
		}

		return new SelectQuery($source, $this->queryExecutor, $this->gateway);
	}

	public function getGateway(): ConversionGateway
	{
		return $this->gateway;
	}

	public function session(?FlushHistory $history = null): Session
	{
		return new Session($this->commandExecutor, history: $history, gateway: $this->gateway);
	}

	public function getCommandExecutor(): CommandExecutorInterface
	{
		return $this->commandExecutor;
	}
}
