<?php
/**
 * clinic_db_clone.php
 *
 * Add this file (or paste its contents) into your admin codebase and include/require it
 * in manage_clinics.php right after you create the new database and save the clinics row.
 *
 * Usage example (after CREATE DATABASE and inserting clinics row):
 *   require_once 'clinic_db_clone.php';
 *   $errors = [];
 *   $ok = cloneDatabaseSchema($pdo, $sourceDb, $newDbName, $excludeTables, $copyDataTables, $errors);
 *   // $errors will contain any non-fatal messages; $ok is boolean
 */

/**
 * Clone the schema (and optionally small seed data) from $sourceDb to $targetDb.
 *
 * @param PDO    $pdo             PDO instance connected to the server (no need to change DB)
 * @param string $sourceDb        Name of the source database (e.g. 'dentitrack_main')
 * @param string $targetDb        Name of the newly created target database
 * @param array  $excludeTables   List of tables (names) to exclude from cloning (management tables)
 * @param array  $copyDataTables  Optional list of tables to copy row data for (seed data)
 * @param array  $errors          (by ref) collects messages and errors during cloning
 * @return bool                   True on success (all CREATE statements executed), false if fatal
 */
function cloneDatabaseSchema(PDO $pdo, string $sourceDb, string $targetDb, array $excludeTables = [], array $copyDataTables = [], array &$errors = []): bool {
    try {
        // 1) Get list of tables in source database
        $sql = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_TYPE='BASE TABLE'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':schema' => $sourceDb]);
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$tables) {
            $errors[] = "No tables found in source database `{$sourceDb}`.";
            return false;
        }

        // 2) Filter out excluded tables
        $tables = array_filter($tables, function($t) use ($excludeTables) {
            return !in_array($t, $excludeTables, true);
        });

        // 3) Disable foreign key checks to avoid FK ordering issues
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0;");

        // 4) For each table retrieve CREATE statement and execute it in target DB
        foreach ($tables as $table) {
            // Get SHOW CREATE TABLE result
            $row = $pdo->query("SHOW CREATE TABLE `{$sourceDb}`.`{$table}`")->fetch(PDO::FETCH_ASSOC);
            if (!$row || !isset($row['Create Table'])) {
                $errors[] = "Failed to get CREATE statement for table `{$table}`.";
                continue;
            }
            $create = $row['Create Table'];

            // Replace "CREATE TABLE `table`" with "CREATE TABLE `targetDb`.`table`"
            $pattern = '/^CREATE TABLE `([^`]+)`/i';
            $replacement = "CREATE TABLE `{$targetDb}`.`$1`";
            $createTarget = preg_replace($pattern, $replacement, $create, 1);

            if ($createTarget === null) {
                $errors[] = "Regex error preparing CREATE for `{$table}`.";
                continue;
            }

            try {
                $pdo->exec($createTarget);
            } catch (PDOException $e) {
                // If table already exists, skip but report; otherwise record error
                if (stripos($e->getMessage(), 'already exists') !== false) {
                    $errors[] = "Table `{$targetDb}`.`{$table}` already exists — skipped.";
                } else {
                    $errors[] = "Error creating `{$targetDb}`.`{$table}`: " . $e->getMessage();
                }
            }
        }

        // 5) Optionally copy small seed data for listed tables
        foreach ($copyDataTables as $ct) {
            // Skip if the table was excluded or not present
            if (!in_array($ct, $tables, true)) continue;

            try {
                $rows = $pdo->query("SELECT * FROM `{$sourceDb}`.`{$ct}`")->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) continue;

                // Prepare INSERT into target table using columns found
                $cols = array_keys($rows[0]);
                $colList = implode(', ', array_map(function($c){ return "`{$c}`"; }, $cols));
                $placeholders = implode(',', array_fill(0, count($cols), '?'));
                $insertSql = "INSERT INTO `{$targetDb}`.`{$ct}` ({$colList}) VALUES ({$placeholders})";
                $insertStmt = $pdo->prepare($insertSql);

                foreach ($rows as $r) {
                    $values = array_values($r);
                    try {
                        $insertStmt->execute($values);
                    } catch (PDOException $e) {
                        // continue on duplicates or errors, but report
                        $errors[] = "Inserting into `{$targetDb}`.`{$ct}` failed: " . $e->getMessage();
                    }
                }
            } catch (PDOException $e) {
                $errors[] = "Failed copying data for table `{$ct}`: " . $e->getMessage();
            }
        }

        // 6) Re-enable FK checks
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1;");
        return true;
    } catch (PDOException $e) {
        $errors[] = "cloneDatabaseSchema fatal error: " . $e->getMessage();
        return false;
    }
}