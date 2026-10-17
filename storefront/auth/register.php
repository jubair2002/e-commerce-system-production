<?php
/**
 * Customer Registration Page (Prompt 12).
 * Editorial minimal styling, anti-enumeration, time-trap & honeypot spam protection.
 */
require_once __DIR__ . '/../../includes/shop_bootstrap.php';

header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store, no-cache, must-revalidate');

$next = customer_url_next($_GET['next'] ?? $_POST['next'] ?? '');
$targetUrl = ($next === 'account/' || empty($next)) ? route('account') : $next;

if (customer_current()) {
    header('Location: ' . $targetUrl);
    exit;
}

$pageTitle = 'Create Account';
$registrationEnabled = (int)setting('customer_registration_enabled', '1') === 1;
$requireVerify = (int)setting('customer_require_email_verification', '1') === 1;
$marketingCheckbox = (int)setting('customer_marketing_checkbox', '1') === 1;
$termsUrl = setting('customer_terms_url', '');

$errors = [];
$successMessage = null;

$form = [
    'name'             => '',
    'email'            => '',
    'phone'            => '',
    'marketing_opt_in' => 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $registrationEnabled) {
    $form['name']             = trim((string)($_POST['name'] ?? ''));
    $form['email']            = trim((string)($_POST['email'] ?? ''));
    $form['phone']            = trim((string)($_POST['phone'] ?? ''));
    $form['marketing_opt_in'] = !empty($_POST['marketing_opt_in']) ? 1 : 0;

    $res = customer_register($_POST);

    if ($res['ok']) {
        $successMessage = $res['message'];
        if (!empty($res['status']) && $res['status'] === 'active') {
            flash('success', $res['message']);
            header('Location: ' . $targetUrl);
            exit;
        }
    } else {
        if (!empty($res['errors'])) {
            $errors = $res['errors'];
        } elseif (!empty($res['error'])) {
            $errors['general'] = $res['error'];
        }
    }
}

include __DIR__ . '/../../includes/partials/header.php';
?>

<div class="auth-split-wrapper">
  <!-- Brand Side Panel -->
  <div class="auth-brand-panel">
    <div>
      <div class="d-inline-flex align-items-center gap-2 mb-4">
        <h2 class="auth-brand-title fw-bold text-dark mb-0"><?= e(setting('site_name', APP_NAME)) ?></h2>
      </div>
      <p class="auth-brand-subtitle text-muted mb-5">
        Join our shopping community for personalized experiences, faster checkouts, and priority parcel dispatches across all 64 districts.
      </p>

      <div class="d-flex flex-column gap-4">
        <div class="d-flex align-items-start gap-3">
          <div class="trust-icon bg-white shadow-sm text-primary">
            <svg class="icon"><use href="<?= e(url('assets/img/icons.svg#check')) ?>"></use></svg>
          </div>
          <div>
            <div class="fw-semibold text-dark">One-Click Reorders</div>
            <div class="small text-muted">Easily review past orders, download invoices, and reorder favourite products.</div>
          </div>
        </div>

        <div class="d-flex align-items-start gap-3">
          <div class="trust-icon bg-white shadow-sm text-primary">
            <svg class="icon"><use href="<?= e(url('assets/img/icons.svg#map-pin')) ?>"></use></svg>
          </div>
          <div>
            <div class="fw-semibold text-dark">Multiple Addresses</div>
            <div class="small text-muted">Save home, office, and family delivery locations for zero-typing checkout.</div>
          </div>
        </div>

        <div class="d-flex align-items-start gap-3">
          <div class="trust-icon bg-white shadow-sm text-primary">
            <svg class="icon"><use href="<?= e(url('assets/img/icons.svg#tag')) ?>"></use></svg>
          </div>
          <div>
            <div class="fw-semibold text-dark">Member Only Deals</div>
            <div class="small text-muted">Receive secret coupon codes and instant alerts for limited seasonal drops.</div>
          </div>
        </div>
      </div>
    </div>

    <div class="small text-muted pt-4 border-top">
      &copy; <?= date('Y') ?> <?= e(setting('site_name', APP_NAME)) ?>. All rights reserved.
    </div>
  </div>

  <!-- Form Panel -->
  <div class="auth-form-panel">
    <div class="auth-card">
      <div class="auth-header text-center mb-4">
        <h1 class="h3 fw-bold text-dark mb-2">Create an Account</h1>
        <p class="text-muted small">Join us today to manage orders, track deliveries, and save your favorites.</p>
      </div>

      <?php if (!$registrationEnabled): ?>
        <div class="alert alert-warning text-center small mb-4">
          <?= icon('info', 'me-1') ?> Customer registration is temporarily disabled. Please check back later.
        </div>
        <div class="text-center">
          <a href="<?= e(route('home')) ?>" class="btn btn-outline-dark btn-sm">Return to Store</a>
        </div>
      <?php elseif ($successMessage): ?>
        <div class="alert alert-success text-center mb-4">
          <div class="fs-4 mb-2"><?= icon('mail') ?></div>
          <h6 class="fw-bold mb-1">Check Your Email</h6>
          <p class="small mb-0"><?= e($successMessage) ?></p>
        </div>
        <div class="d-grid gap-2">
          <a href="<?= e(route('login')) ?>" class="btn btn-primary">
            Proceed to Sign In &rarr;
          </a>
        </div>
      <?php else: ?>
        <?php if (!empty($errors['general'])): ?>
          <div class="alert alert-danger small py-2 mb-3">
            <?= icon('alert-circle', 'me-1') ?> <?= e($errors['general']) ?>
          </div>
        <?php endif; ?>

        <form method="post" action="<?= e(route('register')) ?><?= ($next !== 'account/' && $next !== '') ? '?next=' . urlencode($next) : '' ?>" class="auth-form" novalidate id="registerForm">
          <?= csrf_field() ?>
          <input type="hidden" name="_render_ts" value="<?= time() ?>">
          <input type="hidden" name="next" value="<?= e($next) ?>">

          <!-- Honeypot anti-spam field -->
          <div style="display:none;" aria-hidden="true">
            <label for="website_url">Website</label>
            <input type="text" name="website_url" id="website_url" tabindex="-1" autocomplete="off">
          </div>

          <!-- Full Name -->
          <div class="form-group mb-3">
            <label for="regName" class="form-label small fw-semibold">Full Name <span class="text-danger">*</span></label>
            <input type="text"
                   name="name"
                   id="regName"
                   class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                   value="<?= e($form['name']) ?>"
                   autocomplete="name"
                   required
                   placeholder="e.g. Tanvir Ahmed">
            <?php if (isset($errors['name'])): ?>
              <div class="invalid-feedback small"><?= e($errors['name']) ?></div>
            <?php endif; ?>
          </div>

          <!-- Email Address -->
          <div class="form-group mb-3">
            <label for="regEmail" class="form-label small fw-semibold">Email Address <span class="text-danger">*</span></label>
            <input type="email"
                   name="email"
                   id="regEmail"
                   class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                   value="<?= e($form['email']) ?>"
                   autocomplete="email"
                   inputmode="email"
                   required
                   placeholder="tanvir@example.com">
            <?php if (isset($errors['email'])): ?>
              <div class="invalid-feedback small"><?= e($errors['email']) ?></div>
            <?php endif; ?>
          </div>

          <!-- Phone Number -->
          <div class="form-group mb-3">
            <label for="regPhone" class="form-label small fw-semibold">Phone Number <span class="text-muted fw-normal">(Optional)</span></label>
            <input type="tel"
                   name="phone"
                   id="regPhone"
                   class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>"
                   value="<?= e($form['phone']) ?>"
                   autocomplete="tel"
                   inputmode="tel"
                   placeholder="01712345678">
            <?php if (isset($errors['phone'])): ?>
              <div class="invalid-feedback small"><?= e($errors['phone']) ?></div>
            <?php endif; ?>
          </div>

          <!-- Password -->
          <div class="form-group mb-3">
            <label for="regPassword" class="form-label small fw-semibold">Password <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="password"
                     name="password"
                     id="regPassword"
                     class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                     autocomplete="new-password"
                     required
                     placeholder="At least 8 characters">
              <button type="button" class="btn btn-outline-secondary btn-pw-toggle" data-toggle-password="regPassword" aria-label="Toggle password visibility">
                <?= icon('eye') ?>
              </button>
            </div>
            <div class="password-meter-wrap mt-1">
              <div class="password-meter-bar" id="pwStrengthBar"></div>
            </div>
            <div class="form-text small text-muted" id="pwStrengthText">Use at least 8 characters with letters and numbers.</div>
            <?php if (isset($errors['password'])): ?>
              <div class="invalid-feedback d-block small"><?= e($errors['password']) ?></div>
            <?php endif; ?>
          </div>

          <!-- Confirm Password -->
          <div class="form-group mb-3">
            <label for="regConfirm" class="form-label small fw-semibold">Confirm Password <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="password"
                     name="confirm_password"
                     id="regConfirm"
                     class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>"
                     autocomplete="new-password"
                     required
                     placeholder="Re-enter your password">
              <button type="button" class="btn btn-outline-secondary btn-pw-toggle" data-toggle-password="regConfirm" aria-label="Toggle password visibility">
                <?= icon('eye') ?>
              </button>
            </div>
            <?php if (isset($errors['confirm_password'])): ?>
              <div class="invalid-feedback d-block small"><?= e($errors['confirm_password']) ?></div>
            <?php endif; ?>
          </div>

          <!-- Marketing Opt-in -->
          <?php if ($marketingCheckbox): ?>
            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" name="marketing_opt_in" value="1" id="mktOptIn" <?= $form['marketing_opt_in'] ? 'checked' : '' ?>>
              <label class="form-check-label small text-muted" for="mktOptIn">
                Subscribe to newsletter for exclusive deals and product drops.
              </label>
            </div>
          <?php endif; ?>

          <!-- Terms Agreement -->
          <?php if (!empty($termsUrl)): ?>
            <div class="form-check mb-3">
              <input class="form-check-input <?= isset($errors['agree_terms']) ? 'is-invalid' : '' ?>" type="checkbox" name="agree_terms" value="1" id="agreeTerms" required>
              <label class="form-check-label small text-muted" for="agreeTerms">
                I agree to the <a href="<?= e($termsUrl) ?>" target="_blank" class="text-dark text-decoration-underline">Terms of Service</a> &amp; Privacy Policy.
              </label>
              <?php if (isset($errors['agree_terms'])): ?>
                <div class="invalid-feedback small"><?= e($errors['agree_terms']) ?></div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <div class="d-grid mt-4">
            <button type="submit" class="btn btn-primary py-2 fw-semibold" id="btnSubmitRegister">
              Create Account
            </button>
          </div>
        </form>

        <div class="auth-footer text-center mt-4 pt-3 border-top">
          <span class="small text-muted">Already have an account?</span>
          <a href="<?= e(route('login', ($next !== 'account/' && $next !== '') ? ['next' => $next] : [])) ?>" class="small fw-semibold text-primary ms-1">
            Sign In &rarr;
          </a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="<?= e(url('assets/js/auth.js')) ?>"></script>

<?php include __DIR__ . '/../../includes/partials/footer.php'; ?>
