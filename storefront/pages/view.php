<?php
/**
 * Dynamic Content Page View (Cozastore Edition).
 * Clean editorial presentation for CMS pages (About, Terms, Privacy, FAQ, etc.)
 */
require_once __DIR__ . '/../../includes/shop_bootstrap.php';

$pdo = db();
$slug = trim((string)($_GET['slug'] ?? ''));

if ($slug === '') {
    http_response_code(404);
    require __DIR__ . '/../errors/404.php';
    exit;
}

$page = FileCache::remember("pages:{$slug}", 3600, function () use ($pdo, $slug) {
    $stmt = $pdo->prepare("SELECT id, title, slug, content, meta_title, meta_description, updated_at FROM pages WHERE slug = :slug AND status = 'published' LIMIT 1");
    $stmt->execute([':slug' => $slug]);
    return $stmt->fetch() ?: null;
});

if (!$page) {
    http_response_code(404);
    require __DIR__ . '/../errors/404.php';
    exit;
}

$pageTitle = !empty($page['meta_title']) ? $page['meta_title'] : $page['title'];
$pageDescription = !empty($page['meta_description']) ? $page['meta_description'] : mb_substr(strip_tags($page['content']), 0, 155);

include __DIR__ . '/../../includes/partials/header.php';
?>

<!-- Unified Clean Page Header -->
<section class="sec-page-header p-tb-35" style="background: #f9fafb; border-bottom: 1px solid #eef0f3;">
	<div class="container text-center">
		<h1 class="page-title m-b-6" style="font-size: 28px; font-weight: 700; color: #111827; letter-spacing: -0.5px;">
			<?= htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8') ?>
		</h1>
		<div class="bread-crumb flex-w flex-c-m">
			<a href="<?= htmlspecialchars(route('home'), ENT_QUOTES, 'UTF-8') ?>" class="stext-109 cl8 hov-cl1 trans-04">
				Home <i class="fa fa-angle-right m-l-9 m-r-10" aria-hidden="true"></i>
			</a>
			<span class="stext-109 cl4">
				<?= htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8') ?>
			</span>
		</div>
	</div>
</section>

<!-- =================== CMS PAGE CONTENT =================== -->
<section class="bg0 p-t-30 p-b-120">
	<div class="container">
		<div class="p-b-20 m-b-30 bor12">
			<span class="stext-109 cl9">
				Last updated: <?= date('F j, Y', strtotime($page['updated_at'])) ?>
			</span>
		</div>

		<article class="stext-102 cl6" style="line-height: 2.0; font-size: 15px;">
			<?= $page['content'] ?>
		</article>
	</div>
</section>

<?php include __DIR__ . '/../../includes/partials/footer.php'; ?>
