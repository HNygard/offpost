<?php

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
    $sqlFiles = glob('/opt/offpost/migrations/sql/*.sql');
    sort($sqlFiles); // Ensure files are processed in order

    echo "\nFound migration scripts:\n";
    foreach ($sqlFiles as $file) {
        $filename = basename($file);
        if (isset($executed[$filename])) {
            echo "- $filename (already executed)\n";
        } else {
            echo "- $filename (pending)\n";
        }
    }
    echo "\n";

    // Begin transaction
    $pdo->beginTransaction();

    try {
        foreach ($sqlFiles as $file) {
            $filename = basename($file);
            
            // Skip if already executed
            if (isset($executed[$filename])) {
                echo "Skipping $filename (already executed)\n";
                continue;
            }

            echo "Executing $filename...\n";
            
            // Read and execute SQL file
            $sql = file_get_contents($file);
            $pdo->exec($sql);
            
            // Record migration
            $stmt = $pdo->prepare("INSERT INTO migrations (filename) VALUES (?)");
            $stmt->execute([$filename]);
            
            echo "Completed $filename\n";
        }

        // Commit transaction
        $pdo->commit();
        echo "All migrations completed successfully\n";

    } catch (Exception $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
