<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use Throwable;

final class Database
{
    private PDO $pdo;

    /** @param array<string, int|string> $config */
    public function __construct(array $config)
    {
        $this->pdo = self::connect($config);
    }

    /**
     * Opens a MySQL/MariaDB connection pinned to UTC.
     *
     * The application writes UTC timestamps from PHP and compares them with
     * CURRENT_TIMESTAMP in SQL; without a UTC session every expiry check is
     * shifted by the server offset (on +03:30 hosts, buttons and sessions
     * expire immediately). "localhost" falls back to 127.0.0.1 when the
     * provider exposes MySQL only over TCP.
     *
     * @param array<string, int|string> $config
     */
    public static function connect(array $config): PDO
    {
        $hosts = [(string) $config['host']];
        if (strtolower((string) $config['host']) === 'localhost') {
            $hosts[] = '127.0.0.1';
        }
        $last = null;
        foreach ($hosts as $host) {
            try {
                $pdo = new PDO(
                    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, (int) $config['port'], $config['name'], $config['charset'] ?? 'utf8mb4'),
                    (string) $config['user'],
                    (string) $config['password'],
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                        PDO::ATTR_STRINGIFY_FETCHES => false,
                    ]
                );
                $pdo->exec("SET time_zone = '+00:00'");
                return $pdo;
            } catch (\PDOException $exception) {
                $last = $exception;
                // Access denied / unknown database will not improve on another host.
                if (in_array((int) ($exception->errorInfo[1] ?? $exception->getCode()), [1044, 1045, 1049], true)) {
                    break;
                }
            }
        }
        throw $last ?? new \PDOException('Database connection failed.');
    }

    public static function fromPdo(PDO $pdo): self
    {
        $self = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $self->pdo = $pdo;
        return $self;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @param array<string|int, mixed> $params */
    public function execute(string $sql, array $params = []): PDOStatement
    {
        // fromPdo() is also used by the isolated SQLite security tests. The
        // production constructor always creates MySQL and retains row locks.
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $sql = preg_replace('/\s+FOR\s+UPDATE\b/i', '', $sql) ?? $sql;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    /** @param array<string|int, mixed> $params
     *  @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->execute($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string|int, mixed> $params
     *  @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->execute($sql, $params)->fetchAll();
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }
}
