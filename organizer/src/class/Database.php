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
        if ($stmt->rowCount() != 1) {
            throw new Exception("Expected 1 row, got {$stmt->rowCount()}");
        }
        $result = $stmt->fetch();
        return $result === false ? null : $result;
    }

    /**
     * Execute a query and return a single row
     */
    public static function queryOneOrNone(string $sql, array $params = []): ?array {
        $stmt = self::prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() > 1) {
            throw new Exception("Expected 1 row, got {$stmt->rowCount()}");
        }
        if ($stmt->rowCount() == 0) {
            return null;
        }
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
     * Execute a query with binary data and return a single value
     * 
     * @param string $sql SQL query
     * @param array $params Regular parameters
     * @param array $binaryParams Binary parameters with keys matching placeholders in SQL
     * @return mixed Query result
     */
    public static function queryValueWithBinary(string $sql, array $params = [], array $binaryParams = []): mixed {
        $stmt = self::prepare($sql);
        
        // Bind regular parameters
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        // Bind binary parameters
        foreach ($binaryParams as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_LOB);
        }
        
        // Execute without parameters (they're already bound)
        $stmt->execute();
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
