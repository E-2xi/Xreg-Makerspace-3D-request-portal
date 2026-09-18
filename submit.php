<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function fail(string $message) {
    header('Location: index.php?error=' . urlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Invalid request method.');
}

// --- Validate text fields ---
$name        = trim($_POST['requester_name'] ?? '');
$email       = trim($_POST['requester_email'] ?? '');
$title       = trim($_POST['project_title'] ?? '');
$description = trim($_POST['description'] ?? '');
$material    = trim($_POST['material'] ?? '');
$color       = trim($_POST['color'] ?? '');
$quantity    = (int)($_POST['quantity'] ?? 1);
$neededBy    = trim($_POST['needed_by'] ?? '');
$notes       = trim($_POST['notes'] ?? '');

if ($name === '' || $email === '' || $title === '') {
    fail('Please fill in all required fields.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('Please enter a valid email address.');
}

if ($quantity < 1) {
    $quantity = 1;
}

// --- Validate file upload ---
if (!isset($_FILES['model_file']) || $_FILES['model_file']['error'] === UPLOAD_ERR_NO_FILE) {
    fail('Please attach a model file.');
}

$file = $_FILES['model_file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    fail('There was a problem uploading your file. Please try again.');
}

if ($file['size'] > MAX_UPLOAD_SIZE) {
    fail('File is too large. Max size is ' . (MAX_UPLOAD_SIZE / 1024 / 1024) . ' MB.');
}

$originalName = $file['name'];
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
    fail('File type not allowed. Accepted types: ' . implode(', ', ALLOWED_EXTENSIONS));
}

if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// Build a safe, unique stored filename so uploads never collide or
// allow directory traversal / weird characters through.
$safeBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
$storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safeBase . '.' . $ext;
$destination = UPLOAD_DIR . $storedName;

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    fail('Could not save the uploaded file. Please try again.');
}

// --- Best-effort backup to Google Drive ---
// This never blocks or fails the submission - the local copy in uploads/
// is always the source of truth. If Drive isn't configured, or the
// upload fails for any reason (network, bad credentials, etc.), we just
// record the error so an admin can notice and follow up.
$driveFileId = null;
$driveViewLink = null;
$driveUploadError = null;

if (GOOGLE_DRIVE_ENABLED) {
    require_once __DIR__ . '/google_drive.php';
    try {
        $mimeType = drive_mime_type_for_extension($ext);
        $result = drive_upload_file($destination, $originalName, $mimeType);
        $driveFileId = $result['id'] ?? null;
        $driveViewLink = $result['webViewLink'] ?? null;
    } catch (DriveUploadException $e) {
        $driveUploadError = $e->getMessage();
    }
}

// --- Save to database ---
try {
    $pdo = get_db();
    $now = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        INSERT INTO requests
        (requester_name, requester_email, project_title, description, material,
         color, quantity, needed_by, notes, original_filename, stored_filename,
         status, drive_file_id, drive_view_link, drive_upload_error, created_at, updated_at)
        VALUES
        (:name, :email, :title, :description, :material,
         :color, :quantity, :needed_by, :notes, :original_filename, :stored_filename,
         'pending', :drive_file_id, :drive_view_link, :drive_upload_error, :created_at, :updated_at)
    ");

    $stmt->execute([
        ':name' => $name,
        ':email' => $email,
        ':title' => $title,
        ':description' => $description,
        ':material' => $material,
        ':color' => $color,
        ':quantity' => $quantity,
        ':needed_by' => $neededBy,
        ':notes' => $notes,
        ':original_filename' => $originalName,
        ':stored_filename' => $storedName,
        ':drive_file_id' => $driveFileId,
        ':drive_view_link' => $driveViewLink,
        ':drive_upload_error' => $driveUploadError,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
} catch (Exception $e) {
    // Clean up the orphaned file if the DB write failed
    @unlink($destination);
    fail('Something went wrong saving your request. Please try again.');
}

header('Location: index.php?submitted=1');
exit;
