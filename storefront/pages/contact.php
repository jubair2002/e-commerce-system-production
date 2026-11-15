<?php
/**
 * Contact Us Page (Cozastore Edition).
 * Cozastore contact layout with top banner, contact message form, anti-spam honeypot,
 * and store info cards (Address, Phone, Email).
 */
require_once __DIR__ . '/../../includes/shop_bootstrap.php';
require_once __DIR__ . '/../../includes/mailer.php';

$pdo = db();
$pageTitle = 'Contact Us';
$pageDescription = 'Get in touch with our customer support team for inquiries, order updates, and feedback.';

$errors = [];
$successMessage = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
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
                        'message'      => nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')),
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
$storeEmail = setting('contact_email', setting('store_email', 'support@jinishpotro.store'));
$storePhone = setting('contact_phone', setting('store_phone', '+880 1700-000000'));
$storeAddress = setting('store_address', 'Dhaka, Bangladesh');

include __DIR__ . '/../../includes/partials/header.php';
?>

<!-- Title Banner (Clean Brand Header) -->
<section class="txt-center p-lr-15 p-tb-60 bg3" style="background: linear-gradient(135deg, #1e272e 0%, #2f3542 100%);">
	<h2 class="ltext-105 cl0 txt-center">
		Contact Us
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
			Contact
		</span>
	</div>
</div>

<!-- =================== COZASTORE CONTACT SECTION =================== -->
<section class="bg0 p-t-30 p-b-116">
	<div class="container">

		<!-- Flashes -->
		<?php foreach (get_flashes() as $f): ?>
			<div class="alert alert-<?= htmlspecialchars($f['type'], ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show p-lr-20 p-tb-12 m-b-25" role="alert">
				<?= htmlspecialchars($f['message'], ENT_QUOTES, 'UTF-8') ?>
				<button type="button" class="close" data-dismiss="alert" aria-label="Close">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
		<?php endforeach; ?>

		<!-- Errors -->
		<?php if (!empty($errors)): ?>
			<div class="alert alert-danger alert-dismissible fade show p-lr-20 p-tb-12 m-b-25" role="alert">
				<strong>Please fix the following:</strong>
				<ul class="m-t-5 m-b-0 p-l-20">
					<?php foreach ($errors as $err): ?>
						<li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
					<?php endforeach; ?>
				</ul>
				<button type="button" class="close" data-dismiss="alert" aria-label="Close">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
		<?php endif; ?>

		<div class="flex-w flex-tr">
			<!-- Contact Form Column -->
			<div class="size-210 bor10 p-lr-70 p-t-55 p-b-70 p-lr-15-lg w-full-md">
				<form method="post" action="<?= htmlspecialchars(route('contact'), ENT_QUOTES, 'UTF-8') ?>" novalidate>
					<?= csrf_field() ?>

					<!-- Honeypot -->
					<div style="display: none !important;">
						<input type="text" name="website_hp" value="" tabindex="-1" autocomplete="off">
					</div>

					<h4 class="mtext-105 cl2 txt-center p-b-30">
						Send Us A Message
					</h4>

					<div class="bor8 m-b-20">
						<input class="stext-111 cl2 plh3 size-116 p-lr-25" type="text" name="name" placeholder="Your Name *" required value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
					</div>

					<div class="bor8 m-b-20">
						<input class="stext-111 cl2 plh3 size-116 p-lr-25" type="email" name="email" placeholder="Your Email Address *" required value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
					</div>

					<div class="row m-lr-0 m-b-20" style="gap: 15px;">
						<div class="col-sm p-lr-0 bor8">
							<input class="stext-111 cl2 plh3 size-116 p-lr-20" type="tel" name="phone" placeholder="Phone Number" value="<?= htmlspecialchars($_POST['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
						</div>
						<div class="col-sm p-lr-0 bor8">
							<input class="stext-111 cl2 plh3 size-116 p-lr-20" type="text" name="order_number" placeholder="Order # (Optional)" value="<?= htmlspecialchars($_POST['order_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
						</div>
					</div>

					<div class="bor8 m-b-20">
						<input class="stext-111 cl2 plh3 size-116 p-lr-25" type="text" name="subject" placeholder="Subject" value="<?= htmlspecialchars($_POST['subject'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
					</div>

					<div class="bor8 m-b-30">
						<textarea class="stext-111 cl2 plh3 size-120 p-lr-25 p-tb-20" name="message" placeholder="How Can We Help You? *" required minlength="10"><?= htmlspecialchars($_POST['message'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
					</div>

					<button type="submit" class="flex-c-m stext-101 cl0 size-121 bg3 bor1 hov-btn3 p-lr-15 trans-04 pointer">
						Submit Message
					</button>
				</form>
			</div>

			<!-- Contact Info Column -->
			<div class="size-210 bor10 flex-w flex-col-m p-lr-93 p-tb-30 p-lr-15-lg w-full-md">
				<div class="flex-w w-full p-b-42">
					<span class="fs-18 cl5 txt-center size-211">
						<span class="lnr lnr-map-marker fs-24 cl1"></span>
					</span>

					<div class="size-212 p-t-2">
						<span class="mtext-110 cl2 font-weight-bold">
							Address
						</span>

						<p class="stext-115 cl6 size-213 p-t-10">
							<?= htmlspecialchars($storeAddress, ENT_QUOTES, 'UTF-8') ?>
						</p>
					</div>
				</div>

				<div class="flex-w w-full p-b-42">
					<span class="fs-18 cl5 txt-center size-211">
						<span class="lnr lnr-phone-handset fs-24 cl1"></span>
					</span>

					<div class="size-212 p-t-2">
						<span class="mtext-110 cl2 font-weight-bold">
							Phone Support
						</span>

						<p class="stext-115 cl1 size-213 p-t-10">
							<a href="tel:<?= htmlspecialchars($storePhone, ENT_QUOTES, 'UTF-8') ?>" class="cl1 hov-cl2">
								<?= htmlspecialchars($storePhone, ENT_QUOTES, 'UTF-8') ?>
							</a>
						</p>
					</div>
				</div>

				<div class="flex-w w-full">
					<span class="fs-18 cl5 txt-center size-211">
						<span class="lnr lnr-envelope fs-24 cl1"></span>
					</span>

					<div class="size-212 p-t-2">
						<span class="mtext-110 cl2 font-weight-bold">
							Sales &amp; Customer Care
						</span>

						<p class="stext-115 cl1 size-213 p-t-10">
							<a href="mailto:<?= htmlspecialchars($storeEmail, ENT_QUOTES, 'UTF-8') ?>" class="cl1 hov-cl2">
								<?= htmlspecialchars($storeEmail, ENT_QUOTES, 'UTF-8') ?>
							</a>
						</p>
					</div>
				</div>
			</div>
		</div>
	</div>
</section>

<?php include __DIR__ . '/../../includes/partials/footer.php'; ?>
