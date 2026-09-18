<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (empty($_SESSION['is_admin'])) {
    header('Location: login.php');
    exit;
}

$pdo = get_db();

// --- Handle status update ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $id = (int)$_POST['id'];
    $status = $_POST['status'];
    $redirect = 'admin.php' . (isset($_GET['filter']) ? '?filter=' . urlencode($_GET['filter']) : '');

    if (in_array($status, ['pending', 'printing', 'done'], true)) {
        $check = $pdo->prepare("SELECT * FROM requests WHERE id = :id");
        $check->execute([':id' => $id]);
        $current = $check->fetch(PDO::FETCH_ASSOC);

        if (!$current) {
            header('Location: ' . $redirect);
            exit;
        }

        // Policy: a file can't move to Printing (or Done) until staff have
        // approved it. This is what keeps disallowed content from being
        // printed just because nobody got around to setting the status.
        if (in_array($status, ['printing', 'done'], true) && $current['approval_status'] !== 'approved') {
            header('Location: ' . $redirect . (strpos($redirect, '?') !== false ? '&' : '?') . 'notice=' . urlencode('Approve this request before changing its print status.'));
            exit;
        }

        $statusChanged = $status !== $current['status'];

        $stmt = $pdo->prepare("UPDATE requests SET status = :status, updated_at = :now WHERE id = :id");
        $stmt->execute([
            ':status' => $status,
            ':now' => date('Y-m-d H:i:s'),
            ':id' => $id,
        ]);

        // Notify on Printing/Done only, and only on an actual change -
        // re-saving the same status shouldn't re-send an email.
        if ($statusChanged && in_array($status, ['printing', 'done'], true) && SMTP_ENABLED) {
            require_once __DIR__ . '/mailer.php';

            $statusLabel = $status === 'printing' ? 'Printing' : 'Done';

            $requesterSubject = SITE_TITLE . ': "' . $current['project_title'] . '" is now ' . $statusLabel;
            $requesterBody = "Hi " . $current['requester_name'] . ",\n\n"
                . 'Your 3D print request "' . $current['project_title'] . '" has been updated to: ' . $statusLabel . "\n\n"
                . ($status === 'printing'
                    ? "Your file is now printing."
                    : "Your print is done! Please check with staff about pickup.")
                . "\n\n-- " . SITE_TITLE;

            $staffSubject = '[' . SITE_TITLE . '] Request #' . $current['id'] . ' marked ' . $statusLabel;
            $staffBody = 'Request #' . $current['id'] . ' ("' . $current['project_title'] . '") from '
                . $current['requester_name'] . ' <' . $current['requester_email'] . '> was marked ' . $statusLabel . ".\n\n"
                . 'View it: admin.php';

            try {
                // Requester and staff get different subject/body, so send
                // in two batches (each still one connection per batch).
                $requesterResult = send_notification_emails(
                    [['email' => $current['requester_email'], 'name' => $current['requester_name']]],
                    $requesterSubject,
                    $requesterBody
                );
                $staffRecipients = array_map(fn($e) => ['email' => $e, 'name' => ''], STAFF_NOTIFICATION_EMAILS);
                $staffResult = send_notification_emails($staffRecipients, $staffSubject, $staffBody);

                $failed = $requesterResult['failed'] + $staffResult['failed'];
                if (!empty($failed)) {
                    $redirect .= (strpos($redirect, '?') !== false ? '&' : '?') . 'notice=' . urlencode(
                        'Status updated, but some notification emails failed to send: ' . implode(', ', array_keys($failed))
                    );
                }
            } catch (MailException $e) {
                $redirect .= (strpos($redirect, '?') !== false ? '&' : '?') . 'notice=' . urlencode(
                    'Status updated, but notification emails could not be sent: ' . $e->getMessage()
                );
            }
        }
    }

    header('Location: ' . $redirect);
    exit;
}

// --- Handle approval update ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_approval'])) {
    $id = (int)$_POST['id'];
    $approval = $_POST['approval_status'];

    if (in_array($approval, ['pending', 'approved', 'rejected'], true)) {
        $stmt = $pdo->prepare("UPDATE requests SET approval_status = :approval, updated_at = :now WHERE id = :id");
        $stmt->execute([
            ':approval' => $approval,
            ':now' => date('Y-m-d H:i:s'),
            ':id' => $id,
        ]);
    }

    header('Location: admin.php' . (isset($_GET['filter']) ? '?filter=' . urlencode($_GET['filter']) : ''));
    exit;
}

// --- Handle delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_request'])) {
    $id = (int)$_POST['id'];

    $stmt = $pdo->prepare("SELECT stored_filename FROM requests WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && $row['stored_filename']) {
        $path = UPLOAD_DIR . $row['stored_filename'];
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    $stmt = $pdo->prepare("DELETE FROM requests WHERE id = :id");
    $stmt->execute([':id' => $id]);

    header('Location: admin.php');
    exit;
}

// --- Filters ---
$filter = $_GET['filter'] ?? 'all';
$validFilters = ['all', 'pending', 'printing', 'done'];
if (!in_array($filter, $validFilters, true)) {
    $filter = 'all';
}

$approvalFilter = $_GET['approval'] ?? 'all';
$validApprovalFilters = ['all', 'pending', 'approved', 'rejected'];
if (!in_array($approvalFilter, $validApprovalFilters, true)) {
    $approvalFilter = 'all';
}

$where = [];
$params = [];
if ($filter !== 'all') {
    $where[] = 'status = :status';
    $params[':status'] = $filter;
}
if ($approvalFilter !== 'all') {
    $where[] = 'approval_status = :approval';
    $params[':approval'] = $approvalFilter;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT * FROM requests $whereSql ORDER BY created_at DESC");
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$counts = ['all' => 0, 'pending' => 0, 'printing' => 0, 'done' => 0];
foreach ($pdo->query("SELECT status, COUNT(*) as c FROM requests GROUP BY status") as $row) {
    $counts[$row['status']] = $row['c'];
    $counts['all'] += $row['c'];
}

$approvalCounts = ['all' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($pdo->query("SELECT approval_status, COUNT(*) as c FROM requests GROUP BY approval_status") as $row) {
    $approvalCounts[$row['approval_status']] = $row['c'];
    $approvalCounts['all'] += $row['c'];
}

// carry the current filters through links so switching one doesn't reset the other
function filter_link(string $filter, string $approval): string {
    return 'admin.php?filter=' . urlencode($filter) . '&approval=' . urlencode($approval);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Dashboard &mdash; <?= htmlspecialchars(SITE_TITLE) ?></title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<header>
  <h1><?= htmlspecialchars(SITE_TITLE) ?></h1>
  <nav>
    <a href="index.php">Submit a Request</a>
    <a href="admin.php">Admin</a>
    <a href="logout.php">Log Out</a>
  </nav>
</header>

<main>
  <div class="card">
    <h2>Requests</h2>

    <?php if (isset($_GET['notice'])): ?>
      <div class="alert alert-error"><?= htmlspecialchars($_GET['notice']) ?></div>
    <?php endif; ?>

    <div class="hint" style="margin-bottom:0.3rem;">Print status</div>
    <nav style="margin-bottom:0.75rem;">
      <a class="btn" href="<?= filter_link('all', $approvalFilter) ?>">All (<?= $counts['all'] ?>)</a>
      <a class="btn" href="<?= filter_link('pending', $approvalFilter) ?>">Pending (<?= $counts['pending'] ?>)</a>
      <a class="btn" href="<?= filter_link('printing', $approvalFilter) ?>">Printing (<?= $counts['printing'] ?>)</a>
      <a class="btn" href="<?= filter_link('done', $approvalFilter) ?>">Done (<?= $counts['done'] ?>)</a>
    </nav>

    <div class="hint" style="margin-bottom:0.3rem;">Approval</div>
    <nav style="margin-bottom:1rem;">
      <a class="btn" href="<?= filter_link($filter, 'all') ?>">All (<?= $approvalCounts['all'] ?>)</a>
      <a class="btn" href="<?= filter_link($filter, 'pending') ?>">Needs Review (<?= $approvalCounts['pending'] ?>)</a>
      <a class="btn" href="<?= filter_link($filter, 'approved') ?>">Approved (<?= $approvalCounts['approved'] ?>)</a>
      <a class="btn" href="<?= filter_link($filter, 'rejected') ?>">Rejected (<?= $approvalCounts['rejected'] ?>)</a>
    </nav>

    <?php if (empty($requests)): ?>
      <p>No requests here yet.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Submitted</th>
            <th>Requester</th>
            <th>Project</th>
            <th>Details</th>
            <th>File</th>
            <th>Approved?</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($requests as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['created_at']) ?></td>
              <td>
                <?= htmlspecialchars($r['requester_name']) ?><br>
                <a href="mailto:<?= htmlspecialchars($r['requester_email']) ?>"><?= htmlspecialchars($r['requester_email']) ?></a>
              </td>
              <td>
                <strong><?= htmlspecialchars($r['project_title']) ?></strong>
                <?php if ($r['description']): ?>
                  <br><span class="hint"><?= nl2br(htmlspecialchars($r['description'])) ?></span>
                <?php endif; ?>
              </td>
              <td class="hint">
                <?php if ($r['material']): ?>Material: <?= htmlspecialchars($r['material']) ?><br><?php endif; ?>
                <?php if ($r['color']): ?>Color: <?= htmlspecialchars($r['color']) ?><br><?php endif; ?>
                Qty: <?= (int)$r['quantity'] ?><br>
                <?php if ($r['needed_by']): ?>Needed by: <?= htmlspecialchars($r['needed_by']) ?><br><?php endif; ?>
                <?php if ($r['notes']): ?><em><?= nl2br(htmlspecialchars($r['notes'])) ?></em><?php endif; ?>
              </td>
              <td class="filename-link">
                <?php if ($r['stored_filename']): ?>
                  <a href="uploads/<?= urlencode($r['stored_filename']) ?>" download="<?= htmlspecialchars($r['original_filename']) ?>">
                    <?= htmlspecialchars($r['original_filename']) ?>
                  </a>
                  <?php
                    $ext = strtolower(pathinfo($r['stored_filename'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['stl', 'obj'], true)):
                  ?>
                    <br><a class="btn" style="margin-top:0.35rem;padding:0.2rem 0.6rem;font-size:0.8rem;" href="view.php?id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener">View in 3D</a>
                  <?php else: ?>
                    <br><span class="hint">No 3D preview for .<?= htmlspecialchars($ext) ?></span>
                  <?php endif; ?>

                  <?php if ($r['drive_view_link']): ?>
                    <br><a href="<?= htmlspecialchars($r['drive_view_link']) ?>" target="_blank" rel="noopener" class="hint">Open in Drive</a>
                  <?php elseif ($r['drive_upload_error']): ?>
                    <br><span class="hint" style="color:#a53125;" title="<?= htmlspecialchars($r['drive_upload_error']) ?>">Drive backup failed</span>
                  <?php elseif (GOOGLE_DRIVE_ENABLED): ?>
                    <br><span class="hint">Not backed up to Drive</span>
                  <?php endif; ?>
                <?php else: ?>
                  &mdash;
                <?php endif; ?>
              </td>
              <td>
                <?php
                  $approvalLabels = ['pending' => '?', 'approved' => 'Yes', 'rejected' => 'No'];
                  $approvalBadgeClass = ['pending' => 'badge-approval-pending', 'approved' => 'badge-approved', 'rejected' => 'badge-rejected'];
                  $currentApproval = $r['approval_status'];
                ?>
                <span class="badge <?= $approvalBadgeClass[$currentApproval] ?>"><?= $approvalLabels[$currentApproval] ?></span>
                <form class="status-form" method="post" style="margin-top:0.5rem;">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <select name="approval_status">
                    <option value="pending" <?= $currentApproval === 'pending' ? 'selected' : '' ?>>?</option>
                    <option value="approved" <?= $currentApproval === 'approved' ? 'selected' : '' ?>>Yes</option>
                    <option value="rejected" <?= $currentApproval === 'rejected' ? 'selected' : '' ?>>No</option>
                  </select>
                  <button type="submit" name="update_approval" value="1" style="margin-top:0;padding:0.3rem 0.7rem;font-size:0.85rem;">Set</button>
                </form>
              </td>
              <td>
                <span class="badge badge-<?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars(ucfirst($r['status'])) ?></span>
                <form class="status-form" method="post" style="margin-top:0.5rem;">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <?php $notApproved = $r['approval_status'] !== 'approved'; ?>
                  <select name="status">
                    <option value="pending" <?= $r['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="printing" <?= $r['status'] === 'printing' ? 'selected' : '' ?> <?= $notApproved ? 'disabled' : '' ?>>Printing</option>
                    <option value="done" <?= $r['status'] === 'done' ? 'selected' : '' ?> <?= $notApproved ? 'disabled' : '' ?>>Done</option>
                  </select>
                  <button type="submit" name="update_status" value="1" style="margin-top:0;padding:0.3rem 0.7rem;font-size:0.85rem;">Update</button>
                </form>
                <?php if ($notApproved): ?>
                  <div class="hint">Approve first to unlock printing</div>
                <?php endif; ?>
              </td>
              <td>
                <form method="post" onsubmit="return confirm('Delete this request and its file?');">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" name="delete_request" value="1" style="margin-top:0;background:#a53125;padding:0.3rem 0.7rem;font-size:0.85rem;">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</main>

<footer>3D Print Request Portal &mdash; Admin View</footer>
</body>
</html>
