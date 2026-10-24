<?php
/**
 * Contact Us Page (Form + Store Information + Anti-Spam)
 */
require_once __DIR__ . '/../../includes/shop_bootstrap.php';
require_once __DIR__ . '/../../includes/mailer.php';

$pdo = db();
$pageTitle = 'Contact Us';
$pageDescription = 'Get in touch with our customer support team for inquiries, order updates, and feedback.';

$errors = [];
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    // Honeypot anti-spam check
    $honeypot = trim((string)($_POST['website_hp'] ?? ''));
    if ($honeypot !== '') {
        // Silent rejection for bots
        flash('success', 'Thank you! Your message has been sent successfully.');
        redirect(route('contact'));
    }

    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $orderNumber = trim((string)($_POST['order_number'] ?? ''));
    $subject = trim((string)($_POST['subject'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));

    if ($name === '') {
        $errors[] = 'Please provide your name.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please provide a valid email address.';
    }
    if ($message === '' || mb_strlen($message) < 10) {
        $errors[] = 'Message must be at least 10 characters.';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO contact_messages (name, email, phone, order_number, subject, message, status, ip, created_at)
                 VALUES (:name, :email, :phone, :order_number, :subject, :message, 'new', :ip, NOW())"
            );
            $stmt->execute([
                ':name'         => $name,
                ':email'        => $email,
                ':phone'        => $phone ?: null,
                ':order_number' => $orderNumber ?: null,
                ':subject'      => $subject ?: null,
                ':message'      => $message,
                ':ip'           => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            // Queue administrative notification email
            $notifyAdmin = (int)setting('contact_notify_admin', '1') === 1;
            if ($notifyAdmin) {
                $adminEmail = setting('contact_email', setting('smtp_from_email', ''));
                if ($adminEmail) {
                    enqueue_mail('contact_received_admin', $adminEmail, [
                        'name'         => $name,
                        'email'        => $email,
                        'phone'        => $phone ?: 'N/A',
                        'order_number' => $orderNumber ?: 'N/A',
                        'message'      => nl2br(e($message)),
                    ]);
                }
            }

            flash('success', 'Thank you! Your message has been received. Our support team will reply within 24 hours.');
            redirect(route('contact'));
        } catch (Throwable $e) {
            $errors[] = 'An error occurred while saving your message. Please try again or reach out by phone.';
        }
    }
}

$storeName = setting('site_name', APP_NAME);
$storeEmail = setting('contact_email', setting('store_email', 'support@example.com'));
$storePhone = setting('contact_phone', setting('store_phone', ''));
$storeAddress = setting('store_address', '');

$breadcrumbs = [
    ['label' => 'Contact Us', 'url' => null]
];

include __DIR__ . '/../../includes/partials/header.php';
?>

<div class="container py-4 py-md-5">
  <div class="mx-auto" style="max-width: 960px;">
    <?php include __DIR__ . '/../../includes/partials/breadcrumb.php'; ?>

    <div class="mb-4 pb-2 border-bottom">
      <h1 class="h2 fw-bold mb-1">Get in Touch</h1>
      <p class="text-muted small mb-0">Have questions about an order, products, or deliveries? We are here to help.</p>
    </div>

    <?php foreach (get_flashes() as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?> py-3 mb-4 rounded-2">
        <?= icon('check-circle', 'me-2') ?><?= e($f['message']) ?>
      </div>
    <?php endforeach; ?>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger py-3 mb-4 rounded-2">
        <div class="fw-semibold mb-1"><?= icon('alert-circle', 'me-2') ?>Please fix the following:</div>
        <ul class="mb-0 ps-3 small">
          <?php foreach ($errors as $err): ?>
            <li><?= e($err) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="row justify-content-center">
      <!-- Contact Form -->
      <div class="col-lg-8 col-xl-7">
        <div class="card border shadow-sm p-4 p-md-5 rounded-4">
          <form method="post" action="<?= e(route('contact')) ?>" novalidate>
            <?= csrf_field() ?>

            <!-- Honeypot -->
            <div style="display: none !important;">
              <input type="text" name="website_hp" value="" tabindex="-1" autocomplete="off">
            </div>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label small fw-semibold text-secondary">Your Name <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control" required value="<?= e($_POST['name'] ?? '') ?>" placeholder="John Doe">
              </div>

              <div class="col-md-6">
                <label class="form-label small fw-semibold text-secondary">Email Address <span class="text-danger">*</span></label>
                <input type="email" name="email" class="form-control" required value="<?= e($_POST['email'] ?? '') ?>" placeholder="you@example.com">
              </div>

              <div class="col-md-6">
                <label class="form-label small fw-semibold text-secondary">Phone Number</label>
                <input type="tel" name="phone" class="form-control" value="<?= e($_POST['phone'] ?? '') ?>" placeholder="017xxxxxxxx">
              </div>

              <div class="col-md-6">
                <label class="form-label small fw-semibold text-secondary">Order Number <span class="text-muted fw-normal">(If applicable)</span></label>
                <input type="text" name="order_number" class="form-control" value="<?= e($_POST['order_number'] ?? '') ?>" placeholder="ORD-XXXXX">
              </div>

              <div class="col-12">
                <label class="form-label small fw-semibold text-secondary">Subject</label>
                <input type="text" name="subject" class="form-control" value="<?= e($_POST['subject'] ?? '') ?>" placeholder="Inquiry about delivery, return, etc.">
              </div>

              <div class="col-12">
                <label class="form-label small fw-semibold text-secondary">Your Message <span class="text-danger">*</span></label>
                <textarea name="message" class="form-control" rows="5" required placeholder="How can we help you today?"><?= e($_POST['message'] ?? '') ?></textarea>
              </div>

              <div class="col-12">
                <button type="submit" class="btn btn-editorial-primary px-4 py-2">
                  <?= icon('arrow-right', 'me-1') ?> Send Message
                </button>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
include __DIR__ . '/../../includes/partials/footer.php';
