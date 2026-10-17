# Legacy Redirect Verification Matrix (Phase A)

All legacy URLs are permanently redirected via HTTP 301 to preserve indexed search rankings, bookmarks, and links in transactional emails. Query parameters are strictly preserved (`QSA`).

| # | Legacy Incoming URL | Target (Standard Mode) | Target (Clean/Pretty Mode) | HTTP Code | Query String Preserved |
|---|---|---|---|---|---|
| 1 | `/shop.php` | `/shop/index.php` | `/shop` | 301 | Yes |
| 2 | `/shop.php?sort=newest` | `/shop/index.php?sort=newest` | `/shop?sort=newest` | 301 | Yes |
| 3 | `/category.php?slug=apparel` | `/shop/category.php?slug=apparel` | `/category/apparel` | 301 | Yes |
| 4 | `/product.php?slug=classic-tee` | `/shop/product.php?slug=classic-tee` | `/product/classic-tee` | 301 | Yes |
| 5 | `/cart.php` | `/cart/index.php` | `/cart` | 301 | Yes |
| 6 | `/checkout.php` | `/cart/checkout.php` | `/checkout` | 301 | Yes |
| 7 | `/order_confirmation.php?o=ORD-1&t=x` | `/cart/confirmation.php?o=ORD-1&t=x` | `/order/confirmation?o=ORD-1&t=x` | 301 | Yes |
| 8 | `/track.php` | `/track/index.php` | `/track` | 301 | Yes |
| 9 | `/track.php?order_number=ORD-1&phone=017` | `/track/index.php?order_number=ORD-1&phone=017` | `/track?order_number=ORD-1&phone=017` | 301 | Yes |
| 10 | `/login.php` | `/auth/login.php` | `/login` | 301 | Yes |
| 11 | `/register.php` | `/auth/register.php` | `/register` | 301 | Yes |
| 12 | `/forgot_password.php` | `/auth/forgot-password.php` | `/forgot-password` | 301 | Yes |
| 13 | `/reset_password.php?t=token123` | `/auth/reset-password.php?t=token123` | `/reset-password?t=token123` | 301 | Yes |
| 14 | `/verify_email.php?t=verify456` | `/auth/verify-email.php?t=verify456` | `/verify-email?t=verify456` | 301 | Yes |
| 15 | `/sitemap.php` | `/seo/sitemap.php` | `/sitemap.xml` | 301 | Yes |
