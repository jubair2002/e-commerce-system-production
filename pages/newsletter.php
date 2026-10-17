<?php
/**
 * Newsletter Subscription & Confirmation/Unsubscribe Page
 */
require_once __DIR__ . '/../includes/shop_bootstrap.php';
require_once __DIR__ . '/../includes/mailer.php';

$pdo = db();
$pageTitle = 'Newsletter';
$pageDescription = 'Stay up to date with new arrivals, exclusive discounts, and seasonal offers.';

$token = trim((string)($_GET['t'] ?? ($_GET['token'] ?? '')));
$action = trim((string)($_GET['action'] ?? ''));
$statusMessage = '';
$statusType = 'info';

// 1. Handle Token Confirmation
if ($token !== '') {
    $tokenHash = hash('sha256', $token);
    if ($action === 'unsubscribe') {
        $stmt = $pdo->prepare("SELECT id, email FROM newsletter_subscribers WHERE token_hash = :th LIMIT 1");
        $stmt->execute([':th' => $tokenHash]);
        $sub = $stmt->fetch();
        if ($sub) {
            $pdo->prepare("UPDATE newsletter_subscribers SET status = 'unsubscribed' WHERE id = :id")
                ->execute([':id' => $sub['id']]);
            $statusMessage = 'You have been successfully unsubscribed from our newsletter.';
            $statusType = 'success';
        } else {
            $statusMessage = 'Invalid or expired unsubscribe link.';
            $statusType = 'danger';
        }
    } else {
        // Confirmation
        $stmt = $pdo->prepare("SELECT id, email, status FROM newsletter_subscribers WHERE token_hash = :th LIMIT 1");
        $stmt->execute([':th' => $tokenHash]);
        $sub = $stmt->fetch();
        if ($sub) {
            if ($sub['status'] === 'subscribed') {
                $statusMessage = 'Your newsletter subscription is already confirmed!';
                $statusType = 'success';
            } else {
                $pdo->prepare("UPDATE newsletter_subscribers SET status = 'subscribed', confirmed_at = NOW() WHERE id = :id")
                    ->execute([':id' => $sub['id']]);
                $statusMessage = 'Thank you! Your email has been verified and your subscription is active.';
                $statusType = 'success';
            }
        } else {
            $statusMessage = 'Invalid or expired confirmation link.';
            $statusType = 'danger';
        }
    }
}

// 2. Handle POST Subscribe
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $statusMessage = 'Please enter a valid email address.';
        $statusType = 'danger';
    } else {
        $doubleOptIn = (int)setting('newsletter_double_optin', '1') === 1;
        $rawToken = bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $rawToken);

        $stmt = $pdo->prepare("SELECT id, status FROM newsletter_subscribers WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $existing = $stmt->fetch();

        if ($existing && $existing['status'] === 'subscribed') {
            $statusMessage = 'You are already subscribed to our newsletter!';
            $statusType = 'info';
        } else {
            $initialStatus = $doubleOptIn ? 'pending' : 'subscribed';
            $confirmedAt = $doubleOptIn ? null : date('Y-m-d H:i:s');

            if ($existing) {
                $pdo->prepare(
                    "UPDATE newsletter_subscribers
                     SET status = :status, token_hash = :th, confirmed_at = :ca, updated_at = NOW()
                     WHERE id = :id"
                )->execute([
                    ':status' => $initialStatus,
                    ':th'     => $tokenHash,
                    ':ca'     => $confirmedAt,
                    ':id'     => $existing['id'],
                ]);
            } else {
                $pdo->prepare(
                    "INSERT INTO newsletter_subscribers (email, status, token_hash, source, ip, created_at, confirmed_at)
                     VALUES (:email, :status, :th, 'page', :ip, NOW(), :ca)"
                )->execute([
                    ':email'  => $email,
                    ':status' => $initialStatus,
                    ':th'     => $tokenHash,
                    ':ip'     => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    ':ca'     => $confirmedAt,
                ]);
            }

            if ($doubleOptIn) {
                $confirmUrl = route('newsletter', ['token' => $rawToken]);
                enqueue_mail('newsletter_confirm', $email, [
                    'confirm_url' => $confirmUrl,
                    'site_name'   => setting('site_name', APP_NAME),
                ]);
                $statusMessage = 'A confirmation email has been sent to ' . e($email) . '. Please click the link to confirm your subscription.';
                $statusType = 'success';
            } else {
                $statusMessage = 'Thank you for subscribing to our newsletter!';
                $statusType = 'success';
            }
        }
    }
}

$breadcrumbs = [
    ['label' => 'Newsletter', 'url' => null]
];

include __DIR__ . '/../includes/partials/header.php';
?>

<div class="container py-5 text-center" style="max-width: 600px;">
  <?php include __DIR__ . '/../includes/partials/breadcrumb.php'; ?>

  <div class="p-4 p-md-5 bg-white border rounded-3 shadow-sm my-3">
    <div class="fs-1 text-primary mb-3">
      <i class="bi bi-envelope-heart"></i>
    </div>

    <h1 class="h3 fw-bold text-dark mb-2">Subscribe to Our Newsletter</h1>
    <p class="text-muted small mb-4">
      Receive thoughtful updates, early access to new seasonal products, and private promotions. No spam, ever.
    </p>

    <?php if ($statusMessage !== ''): ?>
      <div class="alert alert-<?= e($statusType) ?> py-3 mb-4 rounded-2 text-start">
        <?= $statusMessage ?>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= e(route('newsletter')) ?>" class="d-flex flex-column gap-3 text-start">
      <?= csrf_field() ?>
      <div>
        <label class="form-label small fw-semibold text-secondary">Your Email Address</label>
        <input type="email" name="email" class="form-control form-control-lg" required placeholder="you@example.com">
      </div>
      <button type="submit" class="btn btn-editorial-primary btn-lg">
        Join Newsletter
      </button>
      <div class="small text-muted text-center mt-1">
        You can unsubscribe at any time with a single click.
      </div>
    </form>
  </div>
</div>

<?php
include __DIR__ . '/../includes/partials/footer.php';
