<?php

declare(strict_types=1);

/** PostgreSQL adapter for the fixture's fixed SQLite dialect; input remains parameterized. */
final class PhpTaskboard_Postgres extends PDO
{
    public function __construct(string $url)
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['postgresql', 'postgres'], true)) {
            throw new RuntimeException('Invalid PostgreSQL connection URL');
        }
        $host = $parts['host'] ?? '127.0.0.1';
        $port = (int)($parts['port'] ?? 5432);
        $database = rawurldecode(ltrim($parts['path'] ?? '', '/'));
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/D', $host) || !preg_match('/^[a-zA-Z0-9_-]+$/D', $database)) {
            throw new RuntimeException('Invalid PostgreSQL connection target');
        }
        try {
            parent::__construct("pgsql:host=$host;port=$port;dbname=$database;connect_timeout=3", rawurldecode($parts['user'] ?? ''), rawurldecode($parts['pass'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => true,
            ]);
        } catch (Throwable) {
            throw new RuntimeException('PostgreSQL connection unavailable');
        }
    }

    private function sql(string $sql): string
    {
        $sql = str_replace(['INTEGER PRIMARY KEY AUTOINCREMENT', "datetime('now')", ' LIKE '], ['SERIAL PRIMARY KEY', 'CURRENT_TIMESTAMP::text', ' ILIKE '], $sql);
        $sql = str_replace("ESCAPE '\\'", 'ESCAPE chr(92)', $sql);
        foreach (['project' => 'INTEGER', 'status' => 'TEXT', 'priority' => 'TEXT', 'q' => 'TEXT'] as $key => $type) {
            $sql = str_replace(":$key IS NULL", "CAST(:$key AS $type) IS NULL", $sql);
        }
        if (str_contains($sql, 'INSERT OR REPLACE INTO')) {
            $column = str_contains($sql, 'heartbeat') ? 'checked_at' : 'seeded_at';
            $sql = str_replace('INSERT OR REPLACE INTO', 'INSERT INTO', $sql)." ON CONFLICT (id) DO UPDATE SET $column = EXCLUDED.$column";
        }
        return $sql;
    }

    public function exec(string $statement): int|false { return parent::exec($this->sql($statement)); }
    public function prepare(string $query, array $options = []): PDOStatement|false { return parent::prepare($this->sql($query), $options); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return $fetchMode === null ? parent::query($this->sql($query)) : parent::query($this->sql($query), $fetchMode, ...$fetchModeArgs);
    }
    public function lastInsertId(?string $name = null): string|false { return (string)parent::query('SELECT LASTVAL()')->fetchColumn(); }
}

function php_tb_postgres_open(string $url): PDO
{
    $pdo = new PhpTaskboard_Postgres($url);
    $pdo->query('SELECT pg_advisory_lock(481904)');
    try {
        $pdo->exec(PHP_TB_SCHEMA_SQL);
        php_tb_seed($pdo);
    } finally {
        $pdo->query('SELECT pg_advisory_unlock(481904)');
    }
    return $pdo;
}
