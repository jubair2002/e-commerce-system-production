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

<!-- Title Banner (Clean Brand Header) -->
<section class="txt-center p-lr-15 p-tb-60 bg3" style="background: linear-gradient(135deg, #1e272e 0%, #2f3542 100%);">
	<h2 class="ltext-105 cl0 txt-center">
		<?= htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8') ?>
	</h2>
</section>

<!-- Breadcrumb -->
<div class="container m-t-20 m-b-20">
	<div class="bread-crumb flex-w p-l-0 p-r-15 p-t-15 p-lr-0-lg">
		<a href="<?= htmlspecialchars(route('home'), ENT_QUOTES, 'UTF-8') ?>" class="stext-109 cl8 hov-cl1 trans-04">
			Home
			<i class="fa fa-angle-right m-l-9 m-r-10" aria-hidden="true"></i>
		</a>

		<span class="stext-109 cl4">
			<?= htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8') ?>
		</span>
	</div>
</div>

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
