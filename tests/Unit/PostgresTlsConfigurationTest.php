<?php

namespace Tests\Unit;

use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Support\Env;
use Tests\TestCase;

class PostgresTlsConfigurationTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalValues = [];

    protected function tearDown(): void
    {
        $repository = Env::getRepository();
        foreach ($this->originalValues as $key => $value) {
            if ($value === null) {
                $repository->clear($key);
            } else {
                $repository->set($key, $value);
            }
        }

        parent::tearDown();
    }

    public function test_postgres_tls_environment_is_mapped_to_connection_config(): void
    {
        $this->setEnvironment([
            'DB_SSLMODE' => 'verify-full',
            'DB_SSLROOTCERT' => '/etc/xdr/postgres/ca.crt',
            'DB_SSLCERT' => '/etc/xdr/postgres/client.crt',
            'DB_SSLKEY' => '/etc/xdr/postgres/client.key',
        ]);

        $config = require config_path('database.php');
        $pgsql = $config['connections']['pgsql'];

        $this->assertSame('verify-full', $pgsql['sslmode']);
        $this->assertSame('/etc/xdr/postgres/ca.crt', $pgsql['sslrootcert']);
        $this->assertSame('/etc/xdr/postgres/client.crt', $pgsql['sslcert']);
        $this->assertSame('/etc/xdr/postgres/client.key', $pgsql['sslkey']);
    }

    public function test_blank_certificate_paths_are_omitted_from_the_native_dsn(): void
    {
        $this->setEnvironment([
            'DB_SSLMODE' => 'prefer',
            'DB_SSLROOTCERT' => '',
            'DB_SSLCERT' => '',
            'DB_SSLKEY' => '',
        ]);

        $config = require config_path('database.php');
        $dsn = $this->dsn($config['connections']['pgsql']);

        $this->assertStringContainsString(';sslmode=prefer', $dsn);
        $this->assertStringNotContainsString('sslrootcert=', $dsn);
        $this->assertStringNotContainsString('sslcert=', $dsn);
        $this->assertStringNotContainsString('sslkey=', $dsn);
    }

    public function test_verify_full_and_client_credentials_are_emitted_by_laravel_connector(): void
    {
        $dsn = $this->dsn([
            'host' => 'postgres',
            'port' => '5432',
            'database' => 'detector',
            'sslmode' => 'verify-full',
            'sslrootcert' => '/certs/ca.crt',
            'sslcert' => '/certs/client.crt',
            'sslkey' => '/certs/client.key',
        ]);

        $this->assertStringContainsString(';sslmode=verify-full', $dsn);
        $this->assertStringContainsString(';sslrootcert=/certs/ca.crt', $dsn);
        $this->assertStringContainsString(';sslcert=/certs/client.crt', $dsn);
        $this->assertStringContainsString(';sslkey=/certs/client.key', $dsn);
    }

    /** @param array<string, mixed> $config */
    private function dsn(array $config): string
    {
        $connector = new class extends PostgresConnector
        {
            /** @param array<string, mixed> $config */
            public function exposeDsn(array $config): string
            {
                return $this->getDsn($config);
            }
        };

        return $connector->exposeDsn($config);
    }

    /** @param array<string, string> $values */
    private function setEnvironment(array $values): void
    {
        $repository = Env::getRepository();
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $this->originalValues)) {
                $this->originalValues[$key] = Env::get($key);
            }
            $repository->set($key, $value);
        }
    }
}
