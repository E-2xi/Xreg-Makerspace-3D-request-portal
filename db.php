<?php
require_once __DIR__ . '/config.php';

function get_db(): PDO {
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dataDir = dirname(DB_PATH);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }

    $isNew = !file_exists(DB_PATH);

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');

    if ($isNew) {
        $pdo->exec("
            CREATE TABLE requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                requester_name TEXT NOT NULL,
                requester_email TEXT NOT NULL,
                project_title TEXT NOT NULL,
                description TEXT,
                material TEXT,
                color TEXT,
                quantity INTEGER DEFAULT 1,
                needed_by TEXT,
                notes TEXT,
                original_filename TEXT,
                stored_filename TEXT,
                status TEXT NOT NULL DEFAULT 'pending',
                approval_status TEXT NOT NULL DEFAULT 'pending',
                drive_file_id TEXT,
                drive_view_link TEXT,
                drive_upload_error TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        ");
    }

    // Migration: add any columns that didn't exist in earlier versions of
    // this project, so upgrading never wipes or breaks existing data.
    $columns = $pdo->query("PRAGMA table_info(requests)")->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($columns, 'name');

    $newColumns = [
        'approval_status'    => "TEXT NOT NULL DEFAULT 'pending'",
        'drive_file_id'      => "TEXT",
        'drive_view_link'    => "TEXT",
        'drive_upload_error' => "TEXT",
    ];

    foreach ($newColumns as $name => $definition) {
        if (!in_array($name, $columnNames, true)) {
            $pdo->exec("ALTER TABLE requests ADD COLUMN $name $definition");
        }
    }

    return $pdo;
}
