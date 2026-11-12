#!/usr/bin/env bash
set -e

echo "=== Starting Jinispotro E-Commerce Production Container ==="

# 1. Export container environment variables to /etc/environment for cron jobs
env | grep -E '^(DB_|AWS_|S3_|STORAGE_|APP_|BASE_|SITE_|METRICS_|MAIL_)' >> /etc/environment 2>/dev/null || true

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

# 4. Ensure upload and cache directories exist with correct permissions
mkdir -p /var/www/html/assets/uploads /var/www/html/storage/cache
chown -R www-data:www-data /var/www/html/assets/uploads /var/www/html/storage
chmod -R 775 /var/www/html/assets/uploads /var/www/html/storage

# 5. Start background cron service
echo "Starting background cron daemon..."
service cron start

echo "=== Jinispotro is ready to serve requests ==="
exec "$@"

