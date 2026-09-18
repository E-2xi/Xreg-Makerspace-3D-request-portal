<?php require_once __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(SITE_TITLE) ?></title>
<link rel="stylesheet" href="style.css">

<script>
  window.filamentColors = <?= json_encode($filamentColors); ?>;
</script>
<script src="script.js" defer></script>

</head>
<body>

<header>
  <h1><?= htmlspecialchars(SITE_TITLE) ?></h1>
  <nav>
    <a href="index.php">Submit a Request</a>
    <a href="admin.php">Admin</a>
  </nav>
</header>

<main>

  <?php if (isset($_GET['submitted'])): ?>
    <div class="alert alert-success">
      Thanks! Your request has been submitted. We'll review it and update its status.
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-error">
      <?= htmlspecialchars($_GET['error']) ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <p>Submit a request to have something 3D printed. Attach your model file (STL, OBJ, 3MF, or a zipped project) and we'll take it from there.</p>

    <form action="submit.php" method="post" enctype="multipart/form-data">

      <div class="two-col">
        <div>
          <label for="requester_name">Your Name *</label>
          <input type="text" id="requester_name" name="requester_name" required>
        </div>
        <div>
          <label for="requester_email">Your Email *</label>
          <input type="email" id="requester_email" name="requester_email" required>
        </div>
      </div>

      <label for="project_title">Project Title *</label>
      <input type="text" id="project_title" name="project_title" required placeholder="e.g. Robotics club gripper prototype">

      <label for="description">Description</label>
      <textarea id="description" name="description" placeholder="What is this for, and any details about the design"></textarea>

      <div class="two-col">
        <div>
          <label for="material">Preferred Material</label>
          <select id="material" name="material">
            <option value="">No preference</option>
            <option value="PLA">PLA</option>
            <option value="PETG">PETG (limited)</option>
            <!-- <option value="ABS">ABS</option> -->
            <!-- <option value="TPU (flexible)">TPU (flexible)</option> -->
            <!-- <option value="Resin">Resin</option> -->
            <option value="Other">Other (see notes)</option>
          </select>
        </div>
        <div>
          <label for="color">Preferred Color</label>
          <!-- <input type="text" id="color" name="color" placeholder="e.g. black, any color"> -->
          <select id="color" name=""color">
            <option value="">Select a material first</option>
          </select>
        </div>
      </div>

      <div class="two-col">
        <div>
          <label for="type_project">Type Material</label>
          <select id="type_project" name="type_project">
            <option value="">Just a fun</option>
            <option value="School">School Project</option>
            <option value="Research">Research Project</option>
            <!-- <option value="ABS">ABS</option> -->
            <!-- <option value="TPU (flexible)">TPU (flexible)</option> -->
            <!-- <option value="Resin">Resin</option> -->
            <option value="Other">Other (see notes)</option>
          </select>
        </div>
        <div>
          <label for="reasons">Reasons, explain</label>
          <textarea id="reasons" name="reasons" placeholder="What are reasons for your project?"></textarea>
        </div>
      </div>

      <div class="two-col">
        <div>
          <label for="quantity">Quantity</label>
          <input type="number" id="quantity" name="quantity" min="1" value="1">
        </div>
        <div>
          <label for="needed_by">Needed By (optional)</label>
          <input type="date" id="needed_by" name="needed_by">
        </div>
      </div>

      <label for="notes">Additional Notes</label>
      <textarea id="notes" name="notes" placeholder="Scale, tolerances, infill preference, anything else we should know"></textarea>

      <label for="model_file">Model File *</label>
      <input type="file" id="model_file" name="model_file" required>
      <div class="hint">Accepted: .stl, .obj, .3mf, .gcode, .zip &mdash; max 50 MB</div>

      <button type="submit">Submit Request</button>
    </form>
  </div>

</main>

<footer>3D Print Request Portal</footer>
</body>
</html>
