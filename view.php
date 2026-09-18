<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (empty($_SESSION['is_admin'])) {
    header('Location: login.php');
    exit;
}

$id = (int)($_GET['id'] ?? 0);

$pdo = get_db();
$stmt = $pdo->prepare("SELECT * FROM requests WHERE id = :id");
$stmt->execute([':id' => $id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request || !$request['stored_filename']) {
    http_response_code(404);
    echo "Request not found.";
    exit;
}

$ext = strtolower(pathinfo($request['stored_filename'], PATHINFO_EXTENSION));

if (!in_array($ext, ['stl', 'obj'], true)) {
    http_response_code(400);
    echo "No 3D preview is available for this file type (." . htmlspecialchars($ext) . ").";
    exit;
}

$fileUrl = 'uploads/' . rawurlencode($request['stored_filename']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>3D Preview &mdash; <?= htmlspecialchars($request['project_title']) ?></title>
<link rel="stylesheet" href="style.css">
<script type="importmap">
{
  "imports": {
    "three": "https://unpkg.com/three@0.160.0/build/three.module.js",
    "three/addons/": "https://unpkg.com/three@0.160.0/examples/jsm/"
  }
}
</script>
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
  <div class="card viewer-wrap">
    <p><a href="admin.php">&larr; Back to dashboard</a></p>
    <h2><?= htmlspecialchars($request['project_title']) ?></h2>
    <p class="hint">
      Submitted by <?= htmlspecialchars($request['requester_name']) ?>
      &mdash; file: <?= htmlspecialchars($request['original_filename']) ?>
    </p>

    <div id="viewer-container">
      <div class="viewer-status" id="viewer-status">Loading model&hellip;</div>
    </div>
    <p class="viewer-hint">Drag to rotate &middot; scroll to zoom &middot; right-click drag to pan. This is a geometry-only preview &mdash; colors shown are not necessarily the requester's intended material/color.</p>

    <p class="viewer-hint">This viewer loads the Three.js library from a public CDN (unpkg.com). If your network blocks external CDNs, this page will show a loading error even though the file itself is fine &mdash; download the file instead to inspect it locally.</p>
  </div>
</main>

<footer>3D Print Request Portal &mdash; Admin View</footer>

<script type="module">
  import * as THREE from 'three';
  import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
  import { STLLoader } from 'three/addons/loaders/STLLoader.js';
  import { OBJLoader } from 'three/addons/loaders/OBJLoader.js';

  const container = document.getElementById('viewer-container');
  const statusEl = document.getElementById('viewer-status');
  const fileUrl = <?= json_encode($fileUrl) ?>;
  const fileExt = <?= json_encode($ext) ?>;

  const scene = new THREE.Scene();
  scene.background = new THREE.Color(0x1c1f22);

  const camera = new THREE.PerspectiveCamera(45, container.clientWidth / container.clientHeight, 0.1, 10000);

  const renderer = new THREE.WebGLRenderer({ antialias: true });
  renderer.setPixelRatio(window.devicePixelRatio);
  renderer.setSize(container.clientWidth, container.clientHeight);
  container.appendChild(renderer.domElement);

  const ambient = new THREE.AmbientLight(0xffffff, 0.6);
  scene.add(ambient);

  const dirLight1 = new THREE.DirectionalLight(0xffffff, 0.8);
  dirLight1.position.set(1, 1, 1);
  scene.add(dirLight1);

  const dirLight2 = new THREE.DirectionalLight(0xffffff, 0.4);
  dirLight2.position.set(-1, -0.5, -1);
  scene.add(dirLight2);

  const grid = new THREE.GridHelper(200, 20, 0x444444, 0x2c2f33);
  scene.add(grid);

  const controls = new OrbitControls(camera, renderer.domElement);
  controls.enableDamping = true;

  function frameObject(object3d) {
    // Center the object on X/Z, then drop it so its lowest point sits at y=0 (on the grid)
    let box = new THREE.Box3().setFromObject(object3d);
    const center = box.getCenter(new THREE.Vector3());
    object3d.position.x -= center.x;
    object3d.position.z -= center.z;

    box = new THREE.Box3().setFromObject(object3d);
    object3d.position.y -= box.min.y;

    box = new THREE.Box3().setFromObject(object3d);
    const size = box.getSize(new THREE.Vector3());
    const finalCenter = box.getCenter(new THREE.Vector3());

    const maxDim = Math.max(size.x, size.y, size.z) || 1;
    const distance = maxDim * 2.2;

    camera.position.set(distance, distance * 0.8, distance);
    camera.near = maxDim / 100;
    camera.far = maxDim * 100;
    camera.updateProjectionMatrix();

    controls.target.set(finalCenter.x, finalCenter.y, finalCenter.z);
    controls.update();

    grid.scale.setScalar(Math.max(1, maxDim / 100));
  }

  function onLoaded(object3d) {
    scene.add(object3d);
    frameObject(object3d);
    statusEl.style.display = 'none';
  }

  function onError(err) {
    console.error(err);
    statusEl.textContent = 'Could not load this model for preview. You can still download the file directly.';
  }

  const material = new THREE.MeshStandardMaterial({
    color: 0x4a90d9,
    metalness: 0.1,
    roughness: 0.65,
  });

  if (fileExt === 'stl') {
    const loader = new STLLoader();
    loader.load(
      fileUrl,
      (geometry) => {
        geometry.computeVertexNormals();
        const mesh = new THREE.Mesh(geometry, material);
        onLoaded(mesh);
      },
      undefined,
      onError
    );
  } else if (fileExt === 'obj') {
    const loader = new OBJLoader();
    loader.load(
      fileUrl,
      (object) => {
        object.traverse((child) => {
          if (child.isMesh) {
            child.material = material;
          }
        });
        onLoaded(object);
      },
      undefined,
      onError
    );
  }

  function animate() {
    requestAnimationFrame(animate);
    controls.update();
    renderer.render(scene, camera);
  }
  animate();

  window.addEventListener('resize', () => {
    camera.aspect = container.clientWidth / container.clientHeight;
    camera.updateProjectionMatrix();
    renderer.setSize(container.clientWidth, container.clientHeight);
  });
</script>

</body>
</html>
