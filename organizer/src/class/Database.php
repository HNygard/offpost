<?php

class Database {
    private static ?PDO $instance = null;
    private static array $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            $host = getenv('DB_HOST') ?: 'postgres';
            $port = getenv('DB_PORT') ?: '5432';
            $dbname = getenv('DB_NAME') ?: 'offpost';
            $user = getenv('DB_USER') ?: 'offpost';
            $passwordFile = getenv('DB_PASSWORD_FILE') ?: '/run/secrets/postgres_password';
            
            if (!file_exists($passwordFile)) {
                throw new RuntimeException("Password file not found: $passwordFile");
            }
            $password = trim(file_get_contents($passwordFile));
            
            $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
            
            try {
                self::$instance = new PDO($dsn, $user, $password, self::$options);
            } catch (PDOException $e) {
                throw new RuntimeException("Connection failed: " . $e->getMessage());
            }
        }
        
        return self::$instance;
    }

    public static function beginTransaction(): bool {
        return self::getInstance()->beginTransaction();
    }

    public static function commit(): bool {
        return self::getInstance()->commit();
    }

    public static function rollBack(): bool {
        return self::getInstance()->rollBack();
    }

    public static function prepare(string $sql): PDOStatement {
        return self::getInstance()->prepare($sql);
    }

    public static function lastInsertId(?string $name = null): string {
        return self::getInstance()->lastInsertId($name);
    }

    /**
     * Execute a query and return all results
     */
    public static function query(string $sql, array $params = []): array {
        $stmt = self::prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Execute a query and return a single row
     */
    public static function queryOne(string $sql, array $params = []): ?array {
        $stmt = self::prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result === false ? null : $result;
    }

    /**
     * Execute a query and return a single value
     */
    public static function queryValue(string $sql, array $params = []): mixed {
        $stmt = self::prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetchColumn();
        return $result === false ? null : $result;
    }

    /**
     * Execute a query that doesn't return results (INSERT, UPDATE, DELETE)
     */
    public static function execute(string $sql, array $params = []): int {
        $stmt = self::prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}
