<?php
/**
 * Dynamic Content Page View (About, Shipping & Returns, FAQ, Privacy, Terms, Size Guide, etc.)
 */
require_once __DIR__ . '/../../includes/shop_bootstrap.php';

$pdo = db();
$slug = trim((string)($_GET['slug'] ?? ''));

if ($slug === '') {
    http_response_code(404);
    require __DIR__ . '/../errors/404.php';
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM pages WHERE slug = :slug AND status = 'published' LIMIT 1");
$stmt->execute([':slug' => $slug]);
$page = $stmt->fetch();

if (!$page) {
    http_response_code(404);
    require __DIR__ . '/../errors/404.php';
    exit;
}

$pageTitle = !empty($page['meta_title']) ? $page['meta_title'] : $page['title'];
$pageDescription = !empty($page['meta_description']) ? $page['meta_description'] : mb_substr(strip_tags($page['content']), 0, 155);

$breadcrumbs = [
    ['label' => $page['title'], 'url' => null]
];

include __DIR__ . '/../../includes/partials/header.php';
?>

<div class="container py-4 py-md-5">
  <div class="mx-auto" style="max-width: 820px;">
    <?php include __DIR__ . '/../../includes/partials/breadcrumb.php'; ?>

    <header class="mb-4 pb-3 border-bottom">
      <h1 class="h2 fw-bold text-dark mb-2"><?= e($page['title']) ?></h1>
      <div class="small text-muted">
        Updated <?= date('F j, Y', strtotime($page['updated_at'])) ?>
      </div>
    </header>

    <article class="content-page-body prose lh-lg text-dark">
      <?= $page['content'] ?>
    </article>
  </div>
</div>

<?php
include __DIR__ . '/../../includes/partials/footer.php';
