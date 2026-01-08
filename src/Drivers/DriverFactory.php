<?php

namespace LucasBritoWdt\LaravelDatabaseFts\Drivers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DriverFactory
{
    protected static array $drivers = [];

    /**
     * Obtém o driver apropriado para a conexão fornecida.
     *
     * @param string|null $connectionName Nome da conexão (null = default)
     * @return DriverInterface
     * @throws RuntimeException Se o driver não for suportado
     */
    public static function make(?string $connectionName = null): DriverInterface
    {
        $cacheKey = $connectionName ?? 'default';

        // Retorna do cache se existir
        if (isset(static::$drivers[$cacheKey])) {
            return static::$drivers[$cacheKey];
        }

        // Verifica configuração manual
        $driverConfig = Config::get('fts.driver', 'auto');

        if ($driverConfig !== 'auto') {
            $driver = static::createDriver($driverConfig, $connectionName);
            static::$drivers[$cacheKey] = $driver;
            return $driver;
        }

        // Detecta automaticamente baseado na conexão
        $driverName = static::detectDriver($connectionName);
        $driver = static::createDriver($driverName, $connectionName);
        static::$drivers[$cacheKey] = $driver;

        return $driver;
    }

    /**
     * Detecta o driver baseado no tipo de conexão do banco de dados.
     *
     * @param string|null $connectionName
     * @return string
     * @throws RuntimeException Se o driver não for suportado
     */
    protected static function detectDriver(?string $connectionName = null): string
    {
        $connection = DB::connection($connectionName);
        $driverName = $connection->getDriverName();

        return match ($driverName) {
            'pgsql' => 'postgres',
            'mysql' => 'mysql',
            default => throw new RuntimeException(
                "Driver de banco de dados '{$driverName}' não é suportado. " .
                    "Drivers suportados: pgsql (PostgreSQL), mysql (MySQL)."
            ),
        };
    }

    /**
     * Cria uma instância do driver especificado.
     *
     * @param string $driverName
     * @param string|null $connectionName
     * @return DriverInterface
     * @throws RuntimeException Se o driver não existir
     */
    protected static function createDriver(string $driverName, ?string $connectionName = null): DriverInterface
    {
        return match ($driverName) {
            'postgres' => new PostgresDriver($connectionName),
            'mysql' => new MySqlDriver($connectionName),
            default => throw new RuntimeException(
                "Driver '{$driverName}' não é suportado. " .
                    "Drivers disponíveis: postgres, mysql."
            ),
        };
    }

    /**
     * Limpa o cache de drivers.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        static::$drivers = [];
    }
}
