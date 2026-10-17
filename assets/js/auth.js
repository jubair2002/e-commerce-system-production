/**
 * Customer Authentication Scripts (Prompt 12).
 * Password visibility toggle, live password strength meter, and validation.
 */
document.addEventListener('DOMContentLoaded', function () {
  // 1. Password Visibility Toggle
  document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var targetId = this.getAttribute('data-toggle-password');
      var input = document.getElementById(targetId);
      if (!input) return;

      var icon = this.querySelector('i');
      var svgUse = this.querySelector('use');
      var spriteUrl = window.SHOP_BASE_URL ? (window.SHOP_BASE_URL + 'assets/img/icons.svg') : 'assets/img/icons.svg';

      if (input.type === 'password') {
        input.type = 'text';
        if (icon) {
          icon.classList.remove('bi-eye');
          icon.classList.add('bi-eye-slash');
        }
        if (svgUse) {
          svgUse.setAttribute('href', spriteUrl + '#eye-off');
        }
        this.setAttribute('aria-label', 'Hide password');
      } else {
        input.type = 'password';
        if (icon) {
          icon.classList.remove('bi-eye-slash');
          icon.classList.add('bi-eye');
        }
        if (svgUse) {
          svgUse.setAttribute('href', spriteUrl + '#eye');
        }
        this.setAttribute('aria-label', 'Show password');
      }
    });
  });

  // 2. Live Password Strength Meter
  var passwordInput = document.getElementById('regPassword') || document.getElementById('resetPassword');
  var meterBar = document.querySelector('.password-meter-bar');
  var meterText = document.querySelector('.password-meter-text');

  if (passwordInput && meterBar && meterText) {
    passwordInput.addEventListener('input', function () {
      var val = this.value;
      if (!val) {
        meterBar.style.width = '0%';
        meterBar.className = 'progress-bar password-meter-bar';
        meterText.textContent = 'Password strength';
        meterText.className = 'password-meter-text small text-muted';
        return;
      }

      var score = calculatePasswordScore(val);
      var width = '0%';
      var colorClass = 'bg-danger';
      var label = 'Too weak';

      switch (score) {
        case 0:
        case 1:
          width = '20%';
          colorClass = 'bg-danger';
          label = 'Weak';
          break;
        case 2:
          width = '45%';
          colorClass = 'bg-warning';
          label = 'Fair';
          break;
        case 3:
          width = '75%';
          colorClass = 'bg-info';
          label = 'Good';
          break;
        case 4:
        default:
          width = '100%';
          colorClass = 'bg-success';
          label = 'Strong';
          break;
      }

      meterBar.style.width = width;
      meterBar.className = 'progress-bar password-meter-bar ' + colorClass;
      meterText.textContent = label;
      meterText.className = 'password-meter-text small fw-semibold ' + (score >= 3 ? 'text-success' : (score === 2 ? 'text-warning' : 'text-danger'));
    });
  }

  function calculatePasswordScore(pass) {
    var score = 0;
    if (pass.length < 8) return 0;
    score++; // Meets minimum length

    if (pass.length >= 12) score++;
    if (/[A-Z]/.test(pass) && /[a-z]/.test(pass)) score++;
    if (/[0-9]/.test(pass)) score++;
    if (/[^A-Za-z0-9]/.test(pass)) score++;

    // Normalize to 0-4
    return Math.min(4, Math.max(1, score - 1));
  }
});
