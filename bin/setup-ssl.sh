#!/usr/bin/env bash
# ==============================================================================
# Automated SSL Certificate Setup for jinishpotro.store via Let's Encrypt Certbot
# Usage: ./bin/setup-ssl.sh [email]
# ==============================================================================

set -e

EMAIL="${1:-admin@jinishpotro.store}"
DOMAIN="jinishpotro.store"

echo "=========================================================="
echo " Starting Let's Encrypt SSL Setup for: $DOMAIN"
echo " Domains: $DOMAIN, www.$DOMAIN"
echo " Admin Email: $EMAIL"
echo "=========================================================="

# 1. Ensure Nginx is running in HTTP mode to handle ACME challenge
echo "Step 1: Ensuring Nginx reverse proxy is running..."
docker compose up -d nginx

# 2. Request certificates via Certbot webroot challenge
echo "Step 2: Requesting SSL certificates from Let's Encrypt..."
docker compose run --rm certbot certonly \
  --webroot \
  -w /var/www/certbot \
  -d "$DOMAIN" \
  -d "www.$DOMAIN" \
  --email "$EMAIL" \
  --agree-tos \
  --no-eff-email

# 3. Activate SSL Nginx configuration
echo "Step 3: Activating HTTPS configuration in Nginx..."
cp docker/nginx/conf.d/jinispotro-ssl.conf.template docker/nginx/conf.d/jinispotro.conf

# 4. Reload Nginx to apply certificates
echo "Step 4: Reloading Nginx..."
docker compose exec nginx nginx -s reload

echo "=========================================================="
echo " SSL Setup Complete!"
echo " • Storefront: https://$DOMAIN"
echo " • WWW:        https://www.$DOMAIN"
echo "=========================================================="
