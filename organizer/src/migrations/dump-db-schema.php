<?php

/**
 * Dumps the current database schema to a file using PDO
 * 
 * @param PDO $pdo Database connection
 * @return bool True if file was updated, false if unchanged
 */
function dumpDatabaseSchema(PDO $pdo): bool {
    $schemaFile = __DIR__ . '/sql/99999-database-schema-after-migrations.sql';
    
    try {
        // Get all tables except migrations table
        $tables = $pdo->query("
            SELECT table_name 
            FROM information_schema.tables 
            WHERE table_schema = 'public' 
            AND table_type = 'BASE TABLE'
            AND table_name != 'migrations'
            ORDER BY table_name
        ")->fetchAll(PDO::FETCH_COLUMN);
        
        $schema = '';
        
        // For each table, get its creation SQL
        foreach ($tables as $table) {
            // Get columns for the table
            $columns = $pdo->query("
                SELECT 
                    column_name,
                    data_type,
                    character_maximum_length,
                    is_nullable,
                    column_default
                FROM 
                    information_schema.columns
                WHERE 
                    table_schema = 'public' 
                    AND table_name = '$table'
                ORDER BY
                    ordinal_position
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            // Format CREATE TABLE with one column per line
            $createTable = "CREATE TABLE $table (\n";
            $columnDefinitions = [];
            
            foreach ($columns as $column) {
                $columnDef = "    " . $column['column_name'] . " " . $column['data_type'];
                
                if ($column['character_maximum_length'] !== null) {
                    $columnDef .= "(" . $column['character_maximum_length'] . ")";
                }
                
                if ($column['is_nullable'] === 'NO') {
                    $columnDef .= " NOT NULL";
                }
                
                if ($column['column_default'] !== null) {
                    $columnDef .= " DEFAULT " . $column['column_default'];
                }
                
                $columnDefinitions[] = $columnDef;
            }
            
            $createTable .= implode(",\n", $columnDefinitions);
            $createTable .= "\n);";
            
            $schema .= $createTable . "\n\n";
            
            // Get primary keys
            $primaryKeys = $pdo->query("
                SELECT
                    'ALTER TABLE ' || tc.table_name || 
                    ' ADD CONSTRAINT ' || tc.constraint_name || 
                    ' PRIMARY KEY (' || 
                    string_agg(kcu.column_name, ', ') || ');' as pk_statement
                FROM 
                    information_schema.table_constraints tc
                JOIN 
                    information_schema.key_column_usage kcu
                    ON tc.constraint_name = kcu.constraint_name
                    AND tc.table_schema = kcu.table_schema
                WHERE 
                    tc.table_schema = 'public'
                    AND tc.table_name = '$table'
                    AND tc.constraint_type = 'PRIMARY KEY'
                GROUP BY 
                    tc.table_name, tc.constraint_name
            ")->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($primaryKeys as $pk) {
                $schema .= $pk . "\n";
            }
            
            // Get foreign keys
            $foreignKeys = $pdo->query("
                SELECT
                    'ALTER TABLE ' || tc.table_name || 
                    ' ADD CONSTRAINT ' || tc.constraint_name || 
                    ' FOREIGN KEY (' || 
                    string_agg(kcu.column_name, ', ') || 
                    ') REFERENCES ' || ccu.table_name || 
                    ' (' || string_agg(ccu.column_name, ', ') || ');' as fk_statement
                FROM 
                    information_schema.table_constraints tc
                JOIN 
                    information_schema.key_column_usage kcu
                    ON tc.constraint_name = kcu.constraint_name
                    AND tc.table_schema = kcu.table_schema
                JOIN 
                    information_schema.constraint_column_usage ccu
                    ON ccu.constraint_name = tc.constraint_name
                    AND ccu.table_schema = tc.table_schema
                WHERE 
                    tc.table_schema = 'public'
                    AND tc.table_name = '$table'
                    AND tc.constraint_type = 'FOREIGN KEY'
                GROUP BY 
                    tc.table_name, tc.constraint_name, ccu.table_name
            ")->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($foreignKeys as $fk) {
                $schema .= $fk . "\n";
            }
            
            // Get indexes
            $indexes = $pdo->query("
                SELECT
                    indexname,
                    tablename,
                    indexdef
                FROM 
                    pg_indexes
                WHERE 
                    schemaname = 'public'
                    AND tablename = '$table'
                    AND indexname NOT LIKE '%_pkey'
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($indexes as $idx) {
                // Extract the index method (btree, gin, etc.) and columns from indexdef
                if (preg_match('/USING\s+(\w+)\s+\((.+)\)/', $idx['indexdef'], $matches)) {
                    $method = $matches[1];
                    $columns = $matches[2];
                    $schema .= "CREATE INDEX " . $idx['indexname'] . " ON " . $idx['tablename'] . " USING " . $method . " (" . $columns . ");\n";
                } else {
                    // Fallback if regex doesn't match
                    $schema .= "CREATE INDEX " . $idx['indexname'] . " ON " . $idx['tablename'] . ";\n";
                }
            }
            
            $schema .= "\n";
        }
        
        // Get functions and triggers
        $functions = $pdo->query("
            SELECT 
                'CREATE OR REPLACE FUNCTION ' || 
                proname || '(' || 
                pg_get_function_arguments(p.oid) || ') ' ||
                'RETURNS ' || pg_get_function_result(p.oid) || ' AS $$ ' ||
                prosrc || ' $$ LANGUAGE ' || l.lanname || ';'
            FROM 
                pg_proc p
            JOIN 
                pg_namespace n ON p.pronamespace = n.oid
            JOIN 
                pg_language l ON p.prolang = l.oid
            WHERE 
                n.nspname = 'public'
        ")->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($functions as $func) {
            $schema .= $func . "\n\n";
        }
        
        // Get triggers
        $triggers = $pdo->query("
            SELECT 
                'CREATE TRIGGER ' || 
                t.tgname || ' ' ||
                CASE WHEN t.tgtype & 2 > 0 THEN 'BEFORE ' 
                     WHEN t.tgtype & 16 > 0 THEN 'AFTER ' 
                     WHEN t.tgtype & 64 > 0 THEN 'INSTEAD OF ' 
                     ELSE '' END ||
                CASE WHEN t.tgtype & 4 > 0 THEN 'INSERT ' 
                     WHEN t.tgtype & 8 > 0 THEN 'DELETE ' 
                     WHEN t.tgtype & 16 > 0 THEN 'UPDATE ' 
                     ELSE '' END ||
                'ON ' || c.relname || ' ' ||
                'FOR EACH ' || 
                CASE WHEN t.tgtype & 1 > 0 THEN 'ROW ' ELSE 'STATEMENT ' END ||
                'EXECUTE FUNCTION ' || p.proname || '();'
            FROM 
                pg_trigger t
            JOIN 
                pg_class c ON t.tgrelid = c.oid
            JOIN 
                pg_proc p ON t.tgfoid = p.oid
            JOIN 
                pg_namespace n ON c.relnamespace = n.oid
            WHERE 
                n.nspname = 'public'
                AND t.tgisinternal = false
        ")->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($triggers as $trig) {
            $schema .= $trig . "\n";
        }
    
        // Add header
        $header = "-- ******************************************************************\n";
        $header .= "-- AUTOMATICALLY GENERATED FILE - DO NOT MODIFY\n";
        $header .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n";
        $header .= "-- \n";
        $header .= "-- This file contains the current database schema after all migrations.\n";
        $header .= "-- It is NOT meant to be executed as a migration script.\n";
        $header .= "-- ******************************************************************\n\n";
        
        // Add section headers to the schema
        $schemaWithSections = "-- ==========================================\n";
        $schemaWithSections .= "-- TABLES\n";
        $schemaWithSections .= "-- ==========================================\n\n";
        $schemaWithSections .= $schema;
        
        // Add functions section header
        $schemaWithSections .= "\n-- ==========================================\n";
        $schemaWithSections .= "-- FUNCTIONS\n";
        $schemaWithSections .= "-- ==========================================\n\n";
        
        $newContent = $header . $schemaWithSections;
    
        // Check if file exists and compare content
        if (file_exists($schemaFile)) {
            $currentContent = file_get_contents($schemaFile);
            
            // Remove timestamp lines for comparison purposes
            $currentContentNoTimestamp = preg_replace('/-- Generated on: .*\n/', '', $currentContent);
            $newContentNoTimestamp = preg_replace('/-- Generated on: .*\n/', '', $newContent);
            
            if ($currentContentNoTimestamp === $newContentNoTimestamp) {
                echo "[migrate] Schema file is already up to date\n";
                return false;
            }
        }
        
        // Write new content to file
        if (file_put_contents($schemaFile, $newContent) === false) {
            echo "[migrate] Error: Failed to write schema file\n";
            return false;
        }
        
        echo "[migrate] Schema file updated successfully\n";
        return true;
    } catch (Exception $e) {
        echo "[migrate] Error generating schema: " . $e->getMessage() . "\n";
        return false;
    }
}
