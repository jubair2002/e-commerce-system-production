#!/usr/bin/env bash
# ==============================================================================
# SSL Certificate Setup & Renewal Guide for jinishpotro.store
# ==============================================================================

set -e

DOMAIN="jinishpotro.store"
EMAIL="${1:-admin@jinishpotro.store}"

echo "=========================================================="
echo " Jinishpotro SSL Certificate Tool"
echo " Domain: $DOMAIN, www.$DOMAIN"
echo "=========================================================="

# Check if certbot is installed on the host
if ! command -v certbot &>/dev/null; then
  echo "Certbot is not installed. To install on Ubuntu/Debian:"
  echo "  sudo apt update && sudo apt install -y certbot"
  exit 1
fi

echo "Option 1: Obtaining certificates with standalone Certbot (Ports 80/443 must be briefly free):"
echo "  sudo systemctl stop apache2 2>/dev/null || true"
echo "  sudo certbot certonly --standalone -d $DOMAIN -d www.$DOMAIN --email $EMAIL --agree-tos --no-eff-email"
echo ""
echo "Option 2: If certificates are already generated at /etc/letsencrypt/live/$DOMAIN/:"
echo "  The Docker container automatically detects them upon launch!"
echo ""
echo "To restart the Docker container with newly loaded certificates:"
echo "  docker compose restart app"
echo "=========================================================="
