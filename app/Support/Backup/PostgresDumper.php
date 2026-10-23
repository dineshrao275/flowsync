<?php

declare(strict_types=1);

namespace App\Support\Backup;

use PDO;
use Throwable;

/**
 * Writes a plain-SQL dump of one PostgreSQL database: `pg_dump` when the binary
 * is available, otherwise a PDO fallback that emits INSERT statements per table.
 */
class PostgresDumper
{
    public function dump(
        string $host,
        string $port,
        string $database,
        string $username,
        string $password,
        string $destPath
    ): bool {
        // First try pg_dump binary if available
        $cmd = sprintf(
            'PGPASSWORD=%s pg_dump -h %s -p %s -U %s -d %s -F p -f %s 2>/dev/null',
            escapeshellarg($password),
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($database),
            escapeshellarg($destPath)
        );

        @exec($cmd, $output, $exitCode);
        if ($exitCode === 0 && file_exists($destPath) && filesize($destPath) > 0) {
            return true;
        }

        // Fallback: Dump schema & tables via PDO connection
        try {
            $pdo = new PDO(
                "pgsql:host={$host};port={$port};dbname={$database}",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $dumpHandle = fopen($destPath, 'w');
            if (! $dumpHandle) {
                return false;
            }

            fwrite($dumpHandle, '-- '.config('app.name')." Database Backup\n-- Database: {$database}\n-- Timestamp: ".date('Y-m-d H:i:s')."\n\n");

            // Fetch all user tables in public schema
            $stmt = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                fwrite($dumpHandle, "-- Table: {$table}\n");
                $dataStmt = $pdo->query(sprintf('SELECT * FROM "%s"', str_replace('"', '""', $table)));
                while ($row = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
                    $cols = array_map(fn ($col) => '"'.str_replace('"', '""', $col).'"', array_keys($row));
                    $vals = array_map(function ($val) use ($pdo) {
                        return $val === null ? 'NULL' : $pdo->quote((string) $val);
                    }, array_values($row));
                    fwrite($dumpHandle, sprintf("INSERT INTO \"%s\" (%s) VALUES (%s);\n", $table, implode(', ', $cols), implode(', ', $vals)));
                }
                fwrite($dumpHandle, "\n");
            }

            fclose($dumpHandle);

            return file_exists($destPath) && filesize($destPath) > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
