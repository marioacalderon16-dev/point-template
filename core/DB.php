<?php
declare(strict_types=1);
namespace Core;

use PDO;
use Exception;
use InvalidArgumentException;

/**
 * Helper para conexión rápida a BD
 */
final class DB
{
    private static ?PDO $connection = null;
    
    public static function connection(): PDO
    {
        if (self::$connection === null) {
            $dsn = $_ENV['DB_DSN'] ?? 'sqlite:' . __DIR__ . '/../database.sqlite';
            $user = $_ENV['DB_USER'] ?? '';
            $pass = $_ENV['DB_PASS'] ?? '';
            
            self::$connection = QueryBuilder::connection($dsn, $user, $pass);
        }
        
        return self::$connection;
    }
    
    public static function table(string $table): QueryBuilder
    {
        return QueryBuilder::table($table, self::connection());
    }
    
    public static function raw(string $sql, array $bindings = []): array
    {
        return (new QueryBuilder(self::connection()))->raw($sql, $bindings);
    }
    
    public static function rawFirst(string $sql, array $bindings = []): ?array
    {
        return (new QueryBuilder(self::connection()))->rawFirst($sql, $bindings);
    }
}