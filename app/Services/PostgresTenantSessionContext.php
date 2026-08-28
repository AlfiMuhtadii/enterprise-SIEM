<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use LogicException;
use Throwable;

class PostgresTenantSessionContext
{
    public const SETTING = 'app.tenant_id';

    public function __construct(private readonly DatabaseManager $database) {}

    public function enabled(): bool
    {
        return (bool) config('xdr.tenancy.rls_session_context_enabled', false);
    }

    public function supportsCurrentConnection(): bool
    {
        return $this->connection()->getDriverName() === 'pgsql';
    }

    /**
     * Run work with a transaction-local PostgreSQL tenant setting.
     * The setting disappears on commit or rollback, preventing pooled
     * connections from retaining one request's tenant context.
     */
    public function run(string $tenantId, callable $callback): mixed
    {
        $connection = $this->connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return $callback();
        }

        if (trim($tenantId) === '') {
            throw new InvalidArgumentException('PostgreSQL tenant context cannot be empty.');
        }

        $initialLevel = $connection->transactionLevel();
        if ($initialLevel !== 0) {
            throw new LogicException('PostgreSQL tenant context must own the outermost transaction.');
        }

        $connection->beginTransaction();

        try {
            $connection->selectOne(
                "select set_config('".self::SETTING."', ?, true) as tenant_id",
                [$tenantId]
            );

            $result = $callback();

            if ($connection->transactionLevel() !== 1) {
                throw new LogicException('Tenant-scoped callback left an unbalanced transaction.');
            }

            $connection->commit();

            return $result;
        } catch (Throwable $exception) {
            while ($connection->transactionLevel() > $initialLevel) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    private function connection(): ConnectionInterface
    {
        return $this->database->connection();
    }
}
