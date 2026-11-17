#!/usr/bin/env bash
set -e

echo "=== Starting Jinishpotro E-Commerce Production Container ==="

# 1. Export container environment variables to /etc/environment for cron jobs
env | grep -E '^(DB_|AWS_|S3_|STORAGE_|APP_|BASE_|SITE_|METRICS_|MAIL_|FORCE_HTTPS)' >> /etc/environment 2>/dev/null || true

# 2. Wait for MySQL database to be accessible
echo "Waiting for MySQL database at ${DB_HOST:-db}:${DB_PORT:-3306}..."
php -r '
$host = getenv("DB_HOST") ?: "db";
$port = getenv("DB_PORT") ?: "3306";
$user = getenv("DB_USER") ?: "jinispotro";
$pass = getenv("DB_PASS") ?: "secret123";
$name = getenv("DB_NAME") ?: "php_admin_panel";

$maxRetries = 40;
$retry = 0;
while ($retry < $maxRetries) {
    try {
        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_TIMEOUT => 3,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        echo "Successfully connected to MySQL database ($name)!\n";
        exit(0);
    } catch (Throwable $e) {
        $retry++;
        echo "Waiting for database connection ($retry/$maxRetries)... " . $e->getMessage() . "\n";
        sleep(2);
    }
}
echo "Database connection failed after $maxRetries attempts.\n";
exit(1);
'

# 3. Automatically apply schema migrations
echo "Applying database schema migrations..."
php bin/migrate.php up

# 4. Ensure upload, cache, and log files exist with correct permissions
mkdir -p /var/www/html/assets/uploads /var/www/html/storage/cache
touch /var/log/storage-sync.log /var/log/collect-metrics.log
chown -R www-data:www-data /var/www/html/assets/uploads /var/www/html/storage /var/log/storage-sync.log /var/log/collect-metrics.log
chmod -R 775 /var/www/html/assets/uploads /var/www/html/storage
chmod 0664 /var/log/storage-sync.log /var/log/collect-metrics.log

# 5. Start background cron service & collect initial system metrics
echo "Starting background cron daemon..."
service cron start
echo "Collecting initial system metrics..."
php /var/www/html/cron/collect_metrics.php 2>/dev/null || true

# 6. Configure SSL Certificate for Apache (Port 443)
echo "Configuring SSL for Apache web server..."
SSL_CERT_PATH=""
SSL_KEY_PATH=""

# Search common Let's Encrypt live certificate locations
for cert_candidate in \
    /etc/letsencrypt/live/jinishpotro.store/fullchain.pem \
    /etc/letsencrypt/live/www.jinishpotro.store/fullchain.pem \
    $(find /etc/letsencrypt/live -name "fullchain.pem" 2>/dev/null); do
    key_candidate="$(dirname "$cert_candidate")/privkey.pem"
    if [ -f "$cert_candidate" ] && [ -f "$key_candidate" ]; then
        SSL_CERT_PATH="$cert_candidate"
        SSL_KEY_PATH="$key_candidate"
        echo "[SSL] Found Let's Encrypt certificate at $SSL_CERT_PATH"
        break
    fi
done

# If no Let's Encrypt certificates found, generate a self-signed fallback certificate
if [ -z "$SSL_CERT_PATH" ]; then
    echo "[SSL] No Let's Encrypt certificates found. Generating self-signed fallback certificate..."
    mkdir -p /etc/ssl/selfsigned
    if [ ! -f /etc/ssl/selfsigned/cert.pem ] || [ ! -f /etc/ssl/selfsigned/privkey.pem ]; then
        openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
            -keyout /etc/ssl/selfsigned/privkey.pem \
            -out /etc/ssl/selfsigned/cert.pem \
            -subj "/CN=jinishpotro.store" 2>/dev/null || true
    fi
    SSL_CERT_PATH="/etc/ssl/selfsigned/cert.pem"
    SSL_KEY_PATH="/etc/ssl/selfsigned/privkey.pem"
fi

export APACHE_SSL_CERT="$SSL_CERT_PATH"
export APACHE_SSL_KEY="$SSL_KEY_PATH"
echo "export APACHE_SSL_CERT=\"$SSL_CERT_PATH\"" >> /etc/apache2/envvars
echo "export APACHE_SSL_KEY=\"$SSL_KEY_PATH\"" >> /etc/apache2/envvars

echo "=== Jinishpotro is ready to serve requests on ports 80 and 443 ==="
exec "$@"
