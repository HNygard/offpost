<?php

echo "[migrate] Starting - " . date('Y-m-d H:i:s') . "\n";

require_once __DIR__ . '/dump-db-schema.php';

function getPasswordFromFile($file) {
    if (!file_exists($file)) {
        throw new RuntimeException("Password file not found: $file");
    }
    return trim(file_get_contents($file));
}

// Database connection settings from environment variables
$host = getenv('DB_HOST') ?: 'postgres';
$port = getenv('DB_PORT') ?: '5432';
$dbname = getenv('DB_NAME') ?: 'offpost';
$user = getenv('DB_USER') ?: 'offpost';
$passwordFile = getenv('DB_PASSWORD_FILE') ?: '/run/secrets/postgres_password';

try {
    // Connect to PostgreSQL
    $password = getPasswordFromFile($passwordFile);
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create migrations table if it doesn't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS migrations (
            id SERIAL PRIMARY KEY,
            filename VARCHAR(255) NOT NULL,
            executed_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Get list of executed migrations
    $executed = $pdo->query("SELECT filename FROM migrations")->fetchAll(PDO::FETCH_COLUMN);
    $executed = array_flip($executed);

    // Get all SQL files from migrations directory
    $sqlFiles = glob(__DIR__ . '/sql/*.sql');
    sort($sqlFiles); // Ensure files are processed in order

    echo "[migrate] \nFound migration scripts:\n";
    foreach ($sqlFiles as $file) {
        $filename = basename($file);
        if (isset($executed[$filename])) {
            echo "[migrate] - $filename (already executed)\n";
        } else {
            echo "[migrate] - $filename (pending)\n";
        }
    }
    echo "[migrate] \n";

    // Separate migrations that must run outside transactions (like ALTER DATABASE)
    $outOfTransactionMigrations = [];
    $regularMigrations = [];
    
    foreach ($sqlFiles as $file) {
        $filename = basename($file);
        if ($filename === '99999-database-schema-after-migrations.sql') {
            continue;
        }
        if (isset($executed[$filename])) {
            continue;
        }
        
        // Check if this migration contains commands that can't run in a transaction
        $sql = file_get_contents($file);
        if (preg_match('/ALTER\s+DATABASE/i', $sql)) {
            $outOfTransactionMigrations[] = $file;
        } else {
            $regularMigrations[] = $file;
        }
    }

    // Execute regular migrations in a transaction
    if (!empty($regularMigrations)) {
        $pdo->beginTransaction();

        try {
            foreach ($regularMigrations as $file) {
                $filename = basename($file);

                echo "[migrate] Executing $filename...\n";
                
                // Read and execute SQL file
                $sql = file_get_contents($file);
                
                // Replace placeholders with environment variables
                // Quote identifier for safety (escape double quotes and wrap in double quotes)
                $quotedDbName = '"' . str_replace('"', '""', $dbname) . '"';
                $sql = str_replace('{{DB_NAME}}', $quotedDbName, $sql);
                
                $pdo->exec($sql);
                
                // Record migration
                $stmt = $pdo->prepare("INSERT INTO migrations (filename) VALUES (?)");
                $stmt->execute([$filename]);
                
                echo "[migrate] Completed $filename\n";
            }

            // Commit transaction
            $pdo->commit();
            echo "[migrate] Regular migrations completed successfully\n";
        } catch (Exception $e) {
            // Rollback transaction on error
            $pdo->rollBack();
            throw $e;
        }
    }
    
    // Execute out-of-transaction migrations separately
    if (!empty($outOfTransactionMigrations)) {
        foreach ($outOfTransactionMigrations as $file) {
            $filename = basename($file);

            echo "[migrate] Executing $filename (outside transaction)...\n";
            
            try {
                // Read and execute SQL file
                $sql = file_get_contents($file);
                
                // Replace placeholders with environment variables
                // Quote identifier for safety (escape double quotes and wrap in double quotes)
                $quotedDbName = '"' . str_replace('"', '""', $dbname) . '"';
                $sql = str_replace('{{DB_NAME}}', $quotedDbName, $sql);
                
                $pdo->exec($sql);
                
                // Record migration
                $stmt = $pdo->prepare("INSERT INTO migrations (filename) VALUES (?)");
                $stmt->execute([$filename]);
                
                echo "[migrate] Completed $filename\n";
            } catch (Exception $e) {
                echo "[migrate] Error executing $filename: " . $e->getMessage() . "\n";
                throw $e;
            }
        }
        echo "[migrate] Out-of-transaction migrations completed successfully\n";
    }
    
    echo "[migrate] All migrations completed successfully\n";
    
    // Dump schema to file
    $schemaUpdated = dumpDatabaseSchema($pdo);
    if ($schemaUpdated) {
        echo "[migrate] Database schema file updated\n";
    } else {
        echo "[migrate] Database schema file unchanged\n";
    }

} catch (Exception $e) {
    echo "[migrate] Error: " . $e->getMessage() . "\n";
    exit(1);
}
