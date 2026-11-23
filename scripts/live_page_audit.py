#!/usr/bin/env python3
import os
import urllib.request
import urllib.parse
import http.cookiejar
import re
import sys
import time
import subprocess

BASE_URL = os.environ.get("BASE_URL", sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8080")

# Ensure test admin and customer users in MySQL
subprocess.run([
    "mysql", "-u", "admin", "-pubuntu", "php_admin_panel", "-e",
    """
    INSERT INTO users (id, name, email, password, role, permissions, status, created_at, updated_at)
    VALUES (999, 'Audit Admin', 'audit_admin@jinishpotro.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', NULL, 'active', NOW(), NOW())
    ON DUPLICATE KEY UPDATE password = VALUES(password), status = 'active';
    """
], check=True, capture_output=True)

# Helper to extract CSRF token
def extract_csrf(html):
    m = re.search(r'name=["\'](?:_csrf|csrf_token)["\']\s+value=["\']([^"\']+)["\']', html)
    if m:
        return m.group(1)
    m2 = re.search(r'value=["\']([^"\']+)["\']\s+name=["\'](?:_csrf|csrf_token)["\']', html)
    if m2:
        return m2.group(1)
    return ""

def extract_title(html):
    m = re.search(r'<title>(.*?)</title>', html, re.IGNORECASE | re.DOTALL)
    if m:
        return m.group(1).strip().replace("\n", " ")[:40]
    return ""

# 1. Admin Session
admin_cj = http.cookiejar.CookieJar()
admin_opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(admin_cj))

login_url = f"{BASE_URL}/admin/login.php"
req = urllib.request.Request(login_url)
with admin_opener.open(req) as resp:
    html = resp.read().decode('utf-8', errors='ignore')
    admin_csrf = extract_csrf(html)

post_data = urllib.parse.urlencode({
    "email": "audit_admin@jinishpotro.test",
    "password": "password",  # standard secret
    "_csrf": admin_csrf,
    "csrf_token": admin_csrf,
}).encode('utf-8')

req = urllib.request.Request(login_url, data=post_data, headers={"Content-Type": "application/x-www-form-urlencoded"})
try:
    with admin_opener.open(req) as resp:
        pass
except urllib.error.HTTPError as e:
    if e.code in (302, 303):
        pass
    else:
        print(f"Admin login failed: {e}")

# 2. Customer Session
cust_cj = http.cookiejar.CookieJar()
cust_opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cust_cj))

anon_opener = urllib.request.build_opener()

# Routes Definition: (group, path, opener, expected_statuses)
test_routes = [
    # Storefront
    ("Storefront", "/", anon_opener, [200]),
    ("Storefront", "/index.php", anon_opener, [200]),
    ("Storefront", "/shop", anon_opener, [200]),
    ("Storefront", "/shop.php", anon_opener, [200, 301]),
    ("Storefront", "/category/women", anon_opener, [200]),
    ("Storefront", "/category.php?slug=women", anon_opener, [200, 301]),
    ("Storefront", "/product/test-simple-product-1794720641", anon_opener, [200, 301]),
    ("Storefront", "/cart", anon_opener, [200]),
    ("Storefront", "/cart.php", anon_opener, [200]),
    ("Storefront", "/checkout", anon_opener, [200, 302, 303]),  # 303 when cart empty
    ("Storefront", "/checkout.php", anon_opener, [200, 301, 302, 303]),
    ("Storefront", "/order/confirmation", anon_opener, [200, 302, 303]),  # 303 when no recent order
    ("Storefront", "/track", anon_opener, [200]),
    ("Storefront", "/track.php", anon_opener, [200]),
    ("Storefront", "/contact", anon_opener, [200]),
    ("Storefront", "/contact.php", anon_opener, [200, 301]),
    ("Storefront", "/sitemap.xml", anon_opener, [200]),
    ("Storefront", "/robots.txt", anon_opener, [200]),
    ("Storefront", "/health", anon_opener, [200]),

    # Customer Auth
    ("Customer Auth", "/login", anon_opener, [200]),
    ("Customer Auth", "/login.php", anon_opener, [200]),
    ("Customer Auth", "/register", anon_opener, [200]),
    ("Customer Auth", "/register.php", anon_opener, [200]),
    ("Customer Auth", "/forgot-password", anon_opener, [200]),
    ("Customer Auth", "/forgot_password.php", anon_opener, [200]),
    ("Customer Auth", "/reset-password", anon_opener, [200, 302, 303]),  # 303 when no token
    ("Customer Auth", "/reset_password.php", anon_opener, [200, 301, 302, 303]),
    ("Customer Auth", "/verify-email", anon_opener, [200, 302, 303]),  # 303 when no token
    ("Customer Auth", "/verify_email.php", anon_opener, [200, 301, 302, 303]),
    ("Customer Auth", "/resend-verification", anon_opener, [200]),
    ("Customer Auth", "/resend_verification.php", anon_opener, [200]),

    # Customer Account (Unauthenticated redirect check)
    ("Customer Account", "/account", anon_opener, [302, 303]),
    ("Customer Account", "/account/orders", anon_opener, [302, 303]),
    ("Customer Account", "/account/addresses", anon_opener, [302, 303]),
    ("Customer Account", "/account/profile", anon_opener, [302, 303]),
    ("Customer Account", "/account/security", anon_opener, [302, 303]),
    ("Customer Account", "/account/wishlist", anon_opener, [302, 303]),

    # Admin Control Panel (Authenticated)
    ("Admin Dashboard", "/admin/dashboard.php", admin_opener, [200]),
    ("Admin Dashboard", "/admin/dashboard", admin_opener, [200]),
    ("Admin Profile", "/admin/profile.php", admin_opener, [200]),
    ("Admin Activity", "/admin/activity.php", admin_opener, [200]),
    ("Admin Catalog", "/admin/products.php", admin_opener, [200]),
    ("Admin Catalog", "/admin/product_form.php", admin_opener, [200]),
    ("Admin Catalog", "/admin/product_import.php", admin_opener, [200]),
    ("Admin Catalog", "/admin/categories.php", admin_opener, [200]),
    ("Admin Catalog", "/admin/category_form.php", admin_opener, [200]),
    ("Admin Inventory", "/admin/inventory.php", admin_opener, [200]),
    ("Admin Inventory", "/admin/inventory.php?tab=ledger", admin_opener, [200]),
    ("Admin Orders", "/admin/orders.php", admin_opener, [200]),
    ("Admin Customers", "/admin/customers.php", admin_opener, [200]),
    ("Admin Marketing", "/admin/reviews.php", admin_opener, [200]),
    ("Admin Marketing", "/admin/messages.php", admin_opener, [200]),
    ("Admin Marketing", "/admin/subscribers.php", admin_opener, [200]),
    ("Admin Marketing", "/admin/banners.php", admin_opener, [200]),
    ("Admin Marketing", "/admin/banner_form.php", admin_opener, [200]),
    ("Admin CMS", "/admin/pages.php", admin_opener, [200]),
    ("Admin CMS", "/admin/page_form.php", admin_opener, [200]),
    ("Admin Users", "/admin/users.php", admin_opener, [200]),
    ("Admin Users", "/admin/user_form.php", admin_opener, [200]),
    ("Admin Settings", "/admin/settings.php", admin_opener, [200]),
    ("Admin Mail", "/admin/mail_settings.php", admin_opener, [200]),
    ("Admin Mail", "/admin/mail_templates.php", admin_opener, [200]),
    ("Admin Mail", "/admin/mail_queue.php", admin_opener, [200]),
    ("Admin Monitoring", "/admin/monitoring.php", admin_opener, [200]),
    ("Admin Analytics", "/admin/analytics.php", admin_opener, [200]),
    ("Admin Analytics", "/admin/analytics_product.php?id=3", admin_opener, [200]),

    # Admin AJAX Feeds
    ("Admin AJAX", "/admin/ajax/stock_update.php", admin_opener, [200]),
    ("Admin AJAX", "/admin/ajax/option_presets.php", admin_opener, [200]),
    ("Admin AJAX", "/admin/ajax/monitor_data.php", admin_opener, [200]),
    ("Admin AJAX", "/admin/ajax/monitor_data.php?format=csv", admin_opener, [200]),
    ("Admin AJAX", "/admin/ajax/analytics_data.php", admin_opener, [200]),

    # Public APIs
    ("Public API", "/api/cart_ajax.php", anon_opener, [200]),
    ("Public API", "/api/availability.php?product_id=3", anon_opener, [200]),
    ("Public API", "/api/search_suggest.php?q=test", anon_opener, [200]),
    ("Public API", "/api/wishlist.php", anon_opener, [200]),
]

print(f"{'Group':<18} | {'Path':<40} | {'Status':<6} | {'Time (ms)':<9} | {'Details'}")
print("-" * 105)

passed = 0
failed = 0

class NoRedirectHandler(urllib.request.HTTPRedirectHandler):
    def http_error_302(self, req, fp, code, msg, headers):
        return fp
    http_error_301 = http_error_302
    http_error_303 = http_error_302
    http_error_307 = http_error_302

for group, path, opener, expected_statuses in test_routes:
    url = f"{BASE_URL}{path}"
    req = urllib.request.Request(url, headers={"User-Agent": "PageAuditRunner/1.0"})
    
    # Custom opener that doesn't follow redirects automatically so we can verify 301/303
    handlers = [h for h in opener.handlers if not isinstance(h, urllib.request.HTTPRedirectHandler)]
    handlers.append(NoRedirectHandler())
    check_opener = urllib.request.build_opener(*handlers)
    
    start = time.time()
    try:
        resp = check_opener.open(req, timeout=5)
        duration_ms = (time.time() - start) * 1000.0
        status = resp.status if hasattr(resp, 'status') else resp.code
        body = resp.read().decode('utf-8', errors='ignore')
        
        detail = ""
        ctype = resp.headers.get('Content-Type', '')
        if "html" in ctype:
            detail = extract_title(body)
        elif "json" in ctype:
            detail = f"JSON ({len(body)} bytes)"
        elif "csv" in ctype:
            detail = f"CSV ({len(body)} bytes)"
        else:
            detail = f"{ctype[:20]}"
            
        if resp.headers.get('Location'):
            detail += f" -> {resp.headers.get('Location')}"

        if status in expected_statuses:
            passed += 1
            mark = "OK"
        else:
            failed += 1
            mark = "FAIL"
            
        print(f"{group:<18} | {path:<40} | {status:<6} | {duration_ms:>7.1f}ms | [{mark}] {detail}")
    except urllib.error.HTTPError as e:
        duration_ms = (time.time() - start) * 1000.0
        status = e.code
        if status in expected_statuses:
            passed += 1
            mark = "OK"
        else:
            failed += 1
            mark = "FAIL"
        loc = e.headers.get('Location', '')
        detail = f"HTTPError {e.code}" + (f" -> {loc}" if loc else "")
        print(f"{group:<18} | {path:<40} | {status:<6} | {duration_ms:>7.1f}ms | [{mark}] {detail}")
    except Exception as e:
        duration_ms = (time.time() - start) * 1000.0
        failed += 1
        print(f"{group:<18} | {path:<40} | {'ERR':<6} | {duration_ms:>7.1f}ms | [FAIL] {e}")

print("-" * 105)
print(f"Total Routes Audited: {len(test_routes)} | Passed: {passed} | Failed: {failed}")
if failed == 0:
    print("ALL ROUTES VERIFIED 100% OPERATIONAL!")
else:
    sys.exit(1)
