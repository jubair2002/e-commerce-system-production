<?php
/**
 * Admin: Bulk Product CSV Import
 *
 * Allows administrators and catalog managers to upload a CSV file to batch-create
 * and update products with full details (name, SKU, category, prices, stock, descriptions,
 * brand, tags, status, and images).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_permission('products.create', 'products.edit');

$pageTitle = 'Import Products via CSV';
$pdo = db();

// -------------------------------------------------------------------------
// Helper: Normalize CSV Header
// -------------------------------------------------------------------------
function normalize_csv_header(string $header): string
{
    $header = trim(strtolower($header));
    $header = preg_replace('/^\xEF\xBB\xBF/', '', $header); // Strip UTF-8 BOM
    $header = str_replace([' ', '-', '_'], '', $header);

    $aliasMap = [
        'name'             => ['name', 'productname', 'title', 'itemname', 'producttitle', 'item'],
        'sku'              => ['sku', 'productsku', 'itemsku', 'itemcode', 'code', 'barcode'],
        'category'         => ['category', 'categoryname', 'cat', 'collection', 'department'],
        'price'            => ['price', 'regularprice', 'retailprice', 'cost', 'mrp', 'standardprice'],
        'sale_price'       => ['saleprice', 'discountprice', 'discountedprice', 'specialprice', 'offerprice'],
        'stock'            => ['stock', 'quantity', 'qty', 'inventory', 'stockcount', 'amount'],
        'short_description'=> ['shortdescription', 'summary', 'brief', 'tagline', 'shortdesc'],
        'description'      => ['description', 'details', 'body', 'fulldescription', 'content', 'desc'],
        'brand'            => ['brand', 'manufacturer', 'vendor', 'maker'],
        'tags'             => ['tags', 'keywords', 'labels'],
        'weight_g'         => ['weight', 'weightg', 'weightinms', 'weightgrams'],
        'image'            => ['image', 'imageurl', 'img', 'photo', 'picture', 'thumbnail'],
        'status'           => ['status', 'visibility', 'state'],
        'featured'         => ['featured', 'isfeatured', 'highlight', 'promoted'],
        'low_stock'        => ['lowstockthreshold', 'lowstock', 'alertstock'],
    ];

    foreach ($aliasMap as $canonical => $aliases) {
        if (in_array($header, $aliases, true)) {
            return $canonical;
        }
    }

    return $header;
}

// -------------------------------------------------------------------------
// Helper: Detect Delimiter
// -------------------------------------------------------------------------
function detect_csv_delimiter(string $filepath): string
{
    $handle = @fopen($filepath, 'r');
    if (!$handle) return ',';
    $firstLine = fgets($handle);
    fclose($handle);
    if (!$firstLine) return ',';

    $delims = [
        ','  => substr_count($firstLine, ','),
        ';'  => substr_count($firstLine, ';'),
        "\t" => substr_count($firstLine, "\t"),
    ];
    arsort($delims);
    return key($delims) ?: ',';
}

// -------------------------------------------------------------------------
// Action 1: Download Sample CSV Template
// -------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'sample_csv') {
    $filename = 'products_import_sample_template.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $out = fopen('php://output', 'w');
    // Write UTF-8 BOM for Microsoft Excel compatibility
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Headers
    fputcsv($out, [
        'name', 'sku', 'category', 'price', 'sale_price', 'stock',
        'short_description', 'description', 'brand', 'tags', 'status', 'featured'
    ]);

    // Sample rows
    fputcsv($out, [
        'Classic Cotton Crewneck T-Shirt',
        'TSHIRT-COT-01',
        'Men',
        '1200.00',
        '999.00',
        '50',
        'Comfortable 100% combed cotton casual tee.',
        'Premium breathable ring-spun cotton t-shirt designed for everyday comfort and durability. Double-needle sleeve and bottom hem.',
        'Jinishpotro',
        'tshirt, cotton, casual, men',
        'active',
        '1'
    ]);

    fputcsv($out, [
        'Floral Summer Maxi Dress',
        'DRESS-FLR-02',
        'Women',
        '2850.00',
        '',
        '35',
        'Lightweight vibrant floral print summer maxi dress.',
        'Elegant maxi dress with soft chiffon lining, tiered skirt, and elastic waist. Perfect for casual day-outs and summer events.',
        'Jinishpotro',
        'dress, women, summer, floral',
        'active',
        '1'
    ]);

    fputcsv($out, [
        'Minimalist Slim Leather Wallet',
        'WLLT-LTH-03',
        'Accessories',
        '850.00',
        '750.00',
        '25',
        'Slim bi-fold genuine top-grain leather wallet.',
        'Ultra-slim profile holding up to 8 cards and full-length cash slot. Built with RFID-blocking security lining.',
        'Jinishpotro',
        'wallet, leather, accessories',
        'active',
        '0'
    ]);

    fputcsv($out, [
        'Chronograph Sport Stainless Watch',
        'WTCH-CHR-04',
        'Watches',
        '4500.00',
        '',
        '15',
        'Water-resistant stainless steel sports chronograph.',
        'Precision quartz movement with sub-dials, date display, luminous hands, and 50m water resistance.',
        'Jinishpotro',
        'watch, sport, chronograph',
        'active',
        '1'
    ]);

    fclose($out);
    exit;
}

// -------------------------------------------------------------------------
// Action 2: Export Current Catalog to CSV
// -------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export_catalog') {
    $filename = 'catalog_export_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($out, [
        'id', 'name', 'sku', 'category', 'price', 'sale_price', 'stock',
        'short_description', 'description', 'brand', 'tags', 'status', 'featured'
    ]);

    $stmt = $pdo->query("
        SELECT p.id, p.name, p.sku, c.name AS category_name, p.price, p.sale_price, p.stock,
               p.short_description, p.description, p.brand, p.tags, p.status, p.featured
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        ORDER BY p.id ASC
    ");

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $row['id'],
            $row['name'],
            $row['sku'] ?? '',
            $row['category_name'] ?? '',
            $row['price'],
            $row['sale_price'] ?? '',
            $row['stock'],
            $row['short_description'] ?? '',
            $row['description'] ?? '',
            $row['brand'] ?? '',
            $row['tags'] ?? '',
            $row['status'],
            $row['featured']
        ]);
    }

    fclose($out);
    exit;
}

// -------------------------------------------------------------------------
// Action 3: Cancel Preview / Clear Session
// -------------------------------------------------------------------------
if (isset($_GET['cancel'])) {
    unset($_SESSION['product_import_bundle'], $_SESSION['product_import_result']);
    flash('info', 'Import session cancelled.');
    admin_redirect('product_import.php');
}

// -------------------------------------------------------------------------
// Action 4: Handle CSV Upload & Dry-Run Preview Generation
// -------------------------------------------------------------------------
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'upload_csv') {
    require_csrf();

    $duplicateMode  = ($_POST['duplicate_mode'] ?? 'update') === 'skip' ? 'skip' : 'update';
    $defaultStatus  = in_array($_POST['default_status'] ?? '', ['active', 'draft', 'inactive'], true) ? $_POST['default_status'] : 'active';
    $autoCategories = !empty($_POST['auto_categories']);

    $uploadErr = $_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($uploadErr !== UPLOAD_ERR_OK) {
        $errors[] = 'Please select a valid CSV file to upload.';
    } else {
        $tmpPath = $_FILES['csv_file']['tmp_name'];
        $origName = $_FILES['csv_file']['name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'txt'], true)) {
            $errors[] = 'Only .csv files are supported.';
        } else {
            $delimiter = detect_csv_delimiter($tmpPath);
            $handle = @fopen($tmpPath, 'r');

            if (!$handle) {
                $errors[] = 'Unable to read the uploaded CSV file.';
            } else {
                $rawHeaders = fgetcsv($handle, 0, $delimiter, '"', "\\");

                if (!$rawHeaders || empty(array_filter($rawHeaders))) {
                    $errors[] = 'CSV file appears to be empty or has an invalid header row.';
                    fclose($handle);
                } else {
                    $headerMap = [];
                    foreach ($rawHeaders as $idx => $rawH) {
                        $norm = normalize_csv_header((string)$rawH);
                        if ($norm !== '') {
                            $headerMap[$norm] = $idx;
                        }
                    }

                    if (!isset($headerMap['name'])) {
                        $errors[] = 'Required column "name" (or "product_name" / "title") was not found in the CSV header.';
                    }
                    if (!isset($headerMap['price'])) {
                        $errors[] = 'Required column "price" (or "regular_price") was not found in the CSV header.';
                    }

                    if (empty($errors)) {
                        // Load existing SKUs and Names for duplicate detection
                        $existingProducts = [];
                        $stmtP = $pdo->query("SELECT id, name, sku, stock FROM products");
                        while ($pRow = $stmtP->fetch(PDO::FETCH_ASSOC)) {
                            if (!empty($pRow['sku'])) {
                                $existingProducts['sku:' . strtolower(trim($pRow['sku']))] = $pRow;
                            }
                            $existingProducts['name:' . strtolower(trim($pRow['name']))] = $pRow;
                        }

                        // Load existing categories
                        $existingCategories = [];
                        $stmtC = $pdo->query("SELECT id, name, slug FROM categories");
                        while ($cRow = $stmtC->fetch(PDO::FETCH_ASSOC)) {
                            $existingCategories[strtolower(trim($cRow['name']))] = (int)$cRow['id'];
                            $existingCategories[strtolower(trim($cRow['slug']))] = (int)$cRow['id'];
                        }

                        $parsedRows = [];
                        $rowNumber = 1; // 1 was header
                        $readyCount = 0;
                        $updateCount = 0;
                        $invalidCount = 0;

                        while (($row = fgetcsv($handle, 0, $delimiter, '"', "\\")) !== false) {
                            $rowNumber++;
                            if (empty(array_filter($row, fn($v) => trim((string)$v) !== ''))) {
                                continue; // Skip completely empty rows
                            }

                            $getVal = function(string $key) use ($headerMap, $row): string {
                                if (isset($headerMap[$key], $row[$headerMap[$key]])) {
                                    return trim((string)$row[$headerMap[$key]]);
                                }
                                return '';
                            };

                            $name             = $getVal('name');
                            $sku              = $getVal('sku');
                            $categoryName     = $getVal('category');
                            $priceRaw         = $getVal('price');
                            $salePriceRaw     = $getVal('sale_price');
                            $stockRaw         = $getVal('stock');
                            $shortDesc        = $getVal('short_description');
                            $desc             = $getVal('description');
                            $brand            = $getVal('brand');
                            $tags             = $getVal('tags');
                            $image            = $getVal('image');
                            $statusVal        = strtolower($getVal('status'));
                            $featuredVal      = strtolower($getVal('featured'));

                            // Row validation
                            $rowErrors = [];

                            if ($name === '') {
                                $rowErrors[] = 'Missing product name';
                            }

                            // Price validation
                            $cleanPrice = str_replace([',', '$', '৳', 'BDT', 'bdt', ' '], '', $priceRaw);
                            if ($cleanPrice === '' || !is_numeric($cleanPrice) || (float)$cleanPrice < 0) {
                                $rowErrors[] = 'Invalid price: "' . htmlspecialchars($priceRaw) . '"';
                                $price = 0.0;
                            } else {
                                $price = (float)$cleanPrice;
                            }

                            // Sale price validation
                            $salePrice = null;
                            if ($salePriceRaw !== '') {
                                $cleanSale = str_replace([',', '$', '৳', 'BDT', 'bdt', ' '], '', $salePriceRaw);
                                if (is_numeric($cleanSale) && (float)$cleanSale >= 0) {
                                    $salePrice = (float)$cleanSale;
                                    if ($salePrice > $price) {
                                        $rowErrors[] = 'Sale price cannot exceed regular price';
                                    }
                                }
                            }

                            // Stock validation
                            $stock = 0;
                            if ($stockRaw !== '') {
                                $stock = max(0, (int)$stockRaw);
                            }

                            // Status validation
                            $status = $defaultStatus;
                            if (in_array($statusVal, ['active', 'draft', 'inactive'], true)) {
                                $status = $statusVal;
                            }

                            // Featured validation
                            $featured = in_array($featuredVal, ['1', 'yes', 'true', 'featured'], true) ? 1 : 0;

                            // Duplicate check
                            $matchedExisting = null;
                            if ($sku !== '' && isset($existingProducts['sku:' . strtolower($sku)])) {
                                $matchedExisting = $existingProducts['sku:' . strtolower($sku)];
                            } elseif ($name !== '' && isset($existingProducts['name:' . strtolower($name)])) {
                                $matchedExisting = $existingProducts['name:' . strtolower($name)];
                            }

                            $actionType = 'create';
                            if ($matchedExisting) {
                                if ($duplicateMode === 'skip') {
                                    $actionType = 'skip';
                                } else {
                                    $actionType = 'update';
                                }
                            }

                            if (!empty($rowErrors)) {
                                $actionType = 'error';
                                $invalidCount++;
                            } elseif ($actionType === 'create') {
                                $readyCount++;
                            } elseif ($actionType === 'update') {
                                $updateCount++;
                            }

                            // Category existence
                            $catExists = false;
                            $catId = null;
                            if ($categoryName !== '') {
                                $lowCat = strtolower($categoryName);
                                if (isset($existingCategories[$lowCat])) {
                                    $catExists = true;
                                    $catId = $existingCategories[$lowCat];
                                }
                            }

                            $parsedRows[] = [
                                'row'            => $rowNumber,
                                'name'           => $name,
                                'sku'            => $sku,
                                'category'       => $categoryName,
                                'category_id'    => $catId,
                                'category_exists'=> $catExists,
                                'price'          => $price,
                                'sale_price'     => $salePrice,
                                'stock'          => $stock,
                                'short_desc'     => $shortDesc,
                                'desc'           => $desc,
                                'brand'          => $brand,
                                'tags'           => $tags,
                                'image'          => $image,
                                'status'         => $status,
                                'featured'       => $featured,
                                'action'         => $actionType,
                                'existing_id'    => $matchedExisting['id'] ?? null,
                                'errors'         => $rowErrors,
                            ];
                        }

                        fclose($handle);

                        if (empty($parsedRows)) {
                            $errors[] = 'No valid data rows found in the uploaded CSV.';
                        } else {
                            $_SESSION['product_import_bundle'] = [
                                'rows'            => $parsedRows,
                                'duplicate_mode'  => $duplicateMode,
                                'default_status'  => $defaultStatus,
                                'auto_categories' => $autoCategories,
                                'ready_count'     => $readyCount,
                                'update_count'    => $updateCount,
                                'invalid_count'   => $invalidCount,
                                'filename'        => $origName,
                            ];

                            admin_redirect('product_import.php?preview=1');
                        }
                    }
                }
            }
        }
    }
}

// -------------------------------------------------------------------------
// Action 5: Execute Import (Commit)
// -------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'commit_import') {
    require_csrf();

    $bundle = $_SESSION['product_import_bundle'] ?? null;
    if (!$bundle || empty($bundle['rows'])) {
        flash('warning', 'No import preview data found. Please upload your CSV file.');
        admin_redirect('product_import.php');
    }

    $autoCategories = !empty($_POST['auto_categories']);
    $rows = $bundle['rows'];

    $created = 0;
    $updated = 0;
    $skipped = 0;
    $failed  = 0;
    $resultsLog = [];

    // Pre-cache categories
    $catMap = [];
    $stmtCats = $pdo->query("SELECT id, name, slug FROM categories");
    while ($c = $stmtCats->fetch(PDO::FETCH_ASSOC)) {
        $catMap[strtolower(trim($c['name']))] = (int)$c['id'];
        $catMap[strtolower(trim($c['slug']))] = (int)$c['id'];
    }

    $pdo->beginTransaction();

    try {
        foreach ($rows as $r) {
            if ($r['action'] === 'error' || !empty($r['errors'])) {
                $skipped++;
                $resultsLog[] = [
                    'row'    => $r['row'],
                    'name'   => $r['name'] ?: 'Row #' . $r['row'],
                    'status' => 'error',
                    'detail' => implode('; ', $r['errors']),
                ];
                continue;
            }

            if ($r['action'] === 'skip') {
                $skipped++;
                $resultsLog[] = [
                    'row'    => $r['row'],
                    'name'   => $r['name'],
                    'status' => 'skipped',
                    'detail' => 'Duplicate item skipped as configured',
                ];
                continue;
            }

            // Resolve or create category
            $finalCatId = null;
            if (!empty($r['category'])) {
                $lowCat = strtolower(trim($r['category']));
                if (isset($catMap[$lowCat])) {
                    $finalCatId = $catMap[$lowCat];
                } elseif ($autoCategories) {
                    $catSlug = unique_slug(slugify($r['category']), 'categories');
                    $stmtInsCat = $pdo->prepare("INSERT INTO categories (name, slug, created_at, updated_at) VALUES (:name, :slug, NOW(), NOW())");
                    $stmtInsCat->execute([':name' => $r['category'], ':slug' => $catSlug]);
                    $finalCatId = (int)$pdo->lastInsertId();
                    $catMap[$lowCat] = $finalCatId;
                    $catMap[strtolower($catSlug)] = $finalCatId;
                }
            }

            // Download or link image if supplied
            $productImage = null;
            if (!empty($r['image'])) {
                $imgStr = trim($r['image']);
                if (filter_var($imgStr, FILTER_VALIDATE_URL) && (str_starts_with($imgStr, 'http://') || str_starts_with($imgStr, 'https://'))) {
                    // Remote URL: attempt safe download
                    try {
                        $ctx = stream_context_create([
                            'http' => ['timeout' => 4, 'user_agent' => 'Jinishpotro-Importer']
                        ]);
                        $imgData = @file_get_contents($imgStr, false, $ctx);
                        if ($imgData !== false && strlen($imgData) > 50) {
                            $finfo = new finfo(FILEINFO_MIME_TYPE);
                            $mime = $finfo->buffer($imgData);
                            $allowedMimes = [
                                'image/jpeg' => 'jpg',
                                'image/png'  => 'png',
                                'image/webp' => 'webp',
                                'image/gif'  => 'gif',
                            ];
                            if (isset($allowedMimes[$mime])) {
                                $ext = $allowedMimes[$mime];
                                $savedName = 'prod_' . uniqid() . '.' . $ext;
                                $targetDir = UPLOADS_PATH . '/products';
                                if (!is_dir($targetDir)) {
                                    @mkdir($targetDir, 0775, true);
                                }
                                if (@file_put_contents($targetDir . '/' . $savedName, $imgData)) {
                                    $productImage = $savedName;
                                }
                            }
                        }
                    } catch (Throwable $e) {}
                } else {
                    // Local filename reference
                    $localPath = UPLOADS_PATH . '/products/' . $imgStr;
                    if (file_exists($localPath)) {
                        $productImage = $imgStr;
                    }
                }
            }

            // Update existing or insert new
            if ($r['action'] === 'update' && !empty($r['existing_id'])) {
                $existingId = (int)$r['existing_id'];

                // Fetch current stock
                $stmtOld = $pdo->prepare("SELECT stock, image FROM products WHERE id = :id");
                $stmtOld->execute([':id' => $existingId]);
                $oldP = $stmtOld->fetch(PDO::FETCH_ASSOC);
                $oldStock = (int)($oldP['stock'] ?? 0);

                if (!$productImage && !empty($oldP['image'])) {
                    $productImage = $oldP['image'];
                }

                $stmtUpd = $pdo->prepare("
                    UPDATE products SET
                        category_id = :cid,
                        name = :name,
                        sku = :sku,
                        short_description = :sd,
                        description = :d,
                        price = :price,
                        sale_price = :sp,
                        brand = :brand,
                        tags = :tags,
                        image = :img,
                        status = :status,
                        featured = :feat,
                        updated_at = NOW()
                    WHERE id = :id
                ");

                $stmtUpd->execute([
                    ':cid'    => $finalCatId,
                    ':name'   => $r['name'],
                    ':sku'    => $r['sku'] !== '' ? $r['sku'] : null,
                    ':sd'     => $r['short_desc'] !== '' ? $r['short_desc'] : null,
                    ':d'      => $r['desc'] !== '' ? $r['desc'] : null,
                    ':price'  => number_format((float)$r['price'], 2, '.', ''),
                    ':sp'     => $r['sale_price'] !== null ? number_format((float)$r['sale_price'], 2, '.', '') : null,
                    ':brand'  => $r['brand'] !== '' ? $r['brand'] : null,
                    ':tags'   => $r['tags'] !== '' ? $r['tags'] : null,
                    ':img'    => $productImage,
                    ':status' => $r['status'],
                    ':feat'   => $r['featured'],
                    ':id'     => $existingId,
                ]);

                // Adjust stock if changed
                $newStock = (int)$r['stock'];
                $stockDelta = $newStock - $oldStock;
                if ($stockDelta !== 0 && function_exists('stock_adjust')) {
                    $reason = ($stockDelta > 0) ? 'restock' : 'manual_adjust';
                    stock_adjust($existingId, null, $stockDelta, $reason, null, 'Stock updated via CSV import');
                }

                $updated++;
                $resultsLog[] = [
                    'row'    => $r['row'],
                    'id'     => $existingId,
                    'name'   => $r['name'],
                    'status' => 'updated',
                    'detail' => 'Updated product #' . $existingId,
                ];
            } else {
                // Create new product
                $slug = unique_slug(slugify($r['name']), 'products');

                $stmtIns = $pdo->prepare("
                    INSERT INTO products
                        (category_id, name, slug, product_type, sku, short_description, description,
                         price, sale_price, stock, image, status, featured,
                         track_inventory, allow_backorder, low_stock_threshold, show_stock_count,
                         brand, tags, created_at, updated_at)
                    VALUES
                        (:cid, :name, :slug, 'simple', :sku, :sd, :d,
                         :price, :sp, 0, :img, :status, :feat,
                         1, 0, 5, 1,
                         :brand, :tags, NOW(), NOW())
                ");

                $stmtIns->execute([
                    ':cid'    => $finalCatId,
                    ':name'   => $r['name'],
                    ':slug'   => $slug,
                    ':sku'    => $r['sku'] !== '' ? $r['sku'] : null,
                    ':sd'     => $r['short_desc'] !== '' ? $r['short_desc'] : null,
                    ':d'      => $r['desc'] !== '' ? $r['desc'] : null,
                    ':price'  => number_format((float)$r['price'], 2, '.', ''),
                    ':sp'     => $r['sale_price'] !== null ? number_format((float)$r['sale_price'], 2, '.', '') : null,
                    ':img'    => $productImage,
                    ':status' => $r['status'],
                    ':feat'   => $r['featured'],
                    ':brand'  => $r['brand'] !== '' ? $r['brand'] : null,
                    ':tags'   => $r['tags'] !== '' ? $r['tags'] : null,
                ]);

                $newProdId = (int)$pdo->lastInsertId();

                // If primary image assigned, insert into product_images gallery
                if ($productImage && $newProdId) {
                    $pdo->prepare("INSERT INTO product_images (product_id, filename, alt_text, position, is_primary) VALUES (?, ?, ?, 0, 1)")
                        ->execute([$newProdId, $productImage, $r['name']]);
                }

                // Initial stock ledger adjustment
                $initialStock = (int)$r['stock'];
                if ($initialStock > 0 && function_exists('stock_adjust')) {
                    stock_adjust($newProdId, null, $initialStock, 'restock', null, 'Initial stock from CSV import');
                }

                $created++;
                $resultsLog[] = [
                    'row'    => $r['row'],
                    'id'     => $newProdId,
                    'name'   => $r['name'],
                    'status' => 'created',
                    'detail' => 'Created product #' . $newProdId . ' (SKU: ' . ($r['sku'] ?: 'N/A') . ')',
                ];
            }
        }

        // Clear storefront and catalog caches
        if (class_exists('FileCache')) {
            FileCache::deletePattern('shop:*');
            FileCache::deletePattern('home:*');
            FileCache::deletePattern('categories:*');
        }

        $pdo->commit();

        log_activity('product.import', "CSV Import completed: Created {$created}, Updated {$updated}, Skipped {$skipped}");

        unset($_SESSION['product_import_bundle']);
        $_SESSION['product_import_result'] = [
            'created'  => $created,
            'updated'  => $updated,
            'skipped'  => $skipped,
            'total'    => count($rows),
            'log'      => $resultsLog,
        ];

        flash('success', "CSV Import completed successfully: {$created} created, {$updated} updated.");
        admin_redirect('product_import.php?result=1');

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = 'Import failed due to a database error: ' . $e->getMessage();
    }
}

// -------------------------------------------------------------------------
// View Data State Determination
// -------------------------------------------------------------------------
$previewBundle = $_SESSION['product_import_bundle'] ?? null;
$importResult  = $_SESSION['product_import_result'] ?? null;
$showPreview   = (!empty($_GET['preview']) && !empty($previewBundle));
$showResult    = (!empty($_GET['result']) && !empty($importResult));

if (PHP_SAPI === 'cli') {
    return;
}

include __DIR__ . '/includes/header.php';
?>

<!-- Header Toolbar -->
<div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4 pb-2 border-bottom">
  <div>
    <div class="mb-2">
      <a href="<?= e(admin_url('products.php')) ?>" class="btn-back">
        <i class="bi bi-arrow-left"></i>
        <span>Back to Products</span>
      </a>
    </div>
    <h3 class="mb-1">Bulk Product CSV Import</h3>
    <p class="text-muted small mb-0">
      Upload a CSV spreadsheet to batch-generate and update products in your store catalog.
    </p>
  </div>
  <div class="d-flex flex-wrap align-items-center gap-2">
    <a href="<?= e(admin_url('product_import.php?action=sample_csv')) ?>" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-2 shadow-sm">
      <i class="bi bi-download"></i>
      <span>Download Sample CSV</span>
    </a>
    <a href="<?= e(admin_url('product_import.php?action=export_catalog')) ?>" class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-2 shadow-sm">
      <i class="bi bi-box-arrow-up-right"></i>
      <span>Export Catalog CSV</span>
    </a>
  </div>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <strong><i class="bi bi-exclamation-triangle-fill me-1"></i> Please check the following issues:</strong>
    <ul class="mb-0 mt-2">
      <?php foreach ($errors as $err): ?>
        <li><?= e($err) ?></li>
      <?php endforeach; ?>
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<!-- ===================================================================== -->
<!-- STATE 1: IMPORT RESULT SUMMARY REPORT                                 -->
<!-- ===================================================================== -->
<?php if ($showResult && $importResult): ?>
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-4 text-center">
      <div class="rounded-circle bg-success-subtle text-success d-inline-flex align-items-center justify-content-center mb-3" style="width: 64px; height: 64px;">
        <i class="bi bi-check-lg fs-1"></i>
      </div>
      <h4 class="fw-bold mb-1">Import Completed Successfully</h4>
      <p class="text-muted small mb-4">Your store catalog and stock inventory have been updated.</p>

      <div class="row g-3 justify-content-center max-w-lg mb-4">
        <div class="col-sm-4">
          <div class="card border bg-light">
            <div class="card-body py-3">
              <div class="fs-3 fw-bold text-success"><?= number_format($importResult['created']) ?></div>
              <div class="small text-muted fw-medium">Products Created</div>
            </div>
          </div>
        </div>
        <div class="col-sm-4">
          <div class="card border bg-light">
            <div class="card-body py-3">
              <div class="fs-3 fw-bold text-primary"><?= number_format($importResult['updated']) ?></div>
              <div class="small text-muted fw-medium">Products Updated</div>
            </div>
          </div>
        </div>
        <div class="col-sm-4">
          <div class="card border bg-light">
            <div class="card-body py-3">
              <div class="fs-3 fw-bold text-secondary"><?= number_format($importResult['skipped']) ?></div>
              <div class="small text-muted fw-medium">Skipped / Unchanged</div>
            </div>
          </div>
        </div>
      </div>

      <div class="d-flex justify-content-center gap-2">
        <a href="<?= e(admin_url('products.php')) ?>" class="btn btn-primary px-4 py-2">
          <i class="bi bi-box-seam me-1"></i> View Products in Catalog
        </a>
        <a href="<?= e(admin_url('product_import.php?cancel=1')) ?>" class="btn btn-outline-secondary px-4 py-2">
          <i class="bi bi-file-earmark-plus me-1"></i> Import Another CSV
        </a>
      </div>
    </div>
  </div>

  <!-- Detailed Results Log -->
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 border-bottom">
      <h5 class="card-title mb-0 d-flex align-items-center gap-2">
        <i class="bi bi-list-check text-primary"></i>
        <span>Processed Items Breakdown (<?= count($importResult['log']) ?> rows)</span>
      </h5>
    </div>
    <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light sticky-top">
          <tr>
            <th style="width: 70px;">Row</th>
            <th>Product Name</th>
            <th style="width: 140px;">Status</th>
            <th>Details</th>
            <th class="text-end" style="width: 110px;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($importResult['log'] as $item): ?>
            <tr>
              <td class="font-monospace text-muted small">#<?= (int)$item['row'] ?></td>
              <td class="fw-semibold"><?= e($item['name']) ?></td>
              <td>
                <?php if ($item['status'] === 'created'): ?>
                  <span class="badge bg-success-subtle text-success border border-success-subtle">Created</span>
                <?php elseif ($item['status'] === 'updated'): ?>
                  <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Updated</span>
                <?php elseif ($item['status'] === 'skipped'): ?>
                  <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Skipped</span>
                <?php else: ?>
                  <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Error</span>
                <?php endif; ?>
              </td>
              <td class="small text-muted"><?= e($item['detail']) ?></td>
              <td class="text-end">
                <?php if (!empty($item['id'])): ?>
                  <a href="<?= e(admin_url('product_form.php?id=' . (int)$item['id'])) ?>" class="btn btn-sm btn-light border" target="_blank" title="Edit Product">
                    <i class="bi bi-pencil"></i>
                  </a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<!-- ===================================================================== -->
<!-- STATE 2: DRY-RUN PREVIEW & CONFIRMATION                               -->
<!-- ===================================================================== -->
<?php elseif ($showPreview && $previewBundle): ?>
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-4">
      <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
          <h5 class="fw-bold mb-1">
            <i class="bi bi-eye-fill text-primary me-1"></i> Dry-Run File Inspection: <?= e($previewBundle['filename']) ?>
          </h5>
          <p class="text-muted small mb-0">
            Review the validated rows below before committing the changes to your catalog database.
          </p>
        </div>
        <div class="d-flex align-items-center gap-2">
          <a href="<?= e(admin_url('product_import.php?cancel=1')) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-x-circle me-1"></i> Cancel & Upload Another
          </a>
        </div>
      </div>

      <!-- Overview Metric Pills -->
      <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
          <div class="card border bg-light stat-card">
            <div class="stat-icon bg-info-subtle text-info">
              <i class="bi bi-file-earmark-spreadsheet"></i>
            </div>
            <div>
              <div class="stat-value"><?= count($previewBundle['rows']) ?></div>
              <div class="stat-label">Total Rows in CSV</div>
            </div>
          </div>
        </div>

        <div class="col-sm-6 col-lg-3">
          <div class="card border bg-light stat-card">
            <div class="stat-icon bg-success-subtle text-success">
              <i class="bi bi-plus-circle"></i>
            </div>
            <div>
              <div class="stat-value"><?= $previewBundle['ready_count'] ?></div>
              <div class="stat-label">Ready to Create</div>
            </div>
          </div>
        </div>

        <div class="col-sm-6 col-lg-3">
          <div class="card border bg-light stat-card">
            <div class="stat-icon bg-primary-subtle text-primary">
              <i class="bi bi-arrow-repeat"></i>
            </div>
            <div>
              <div class="stat-value"><?= $previewBundle['update_count'] ?></div>
              <div class="stat-label">Existing (To Update)</div>
            </div>
          </div>
        </div>

        <div class="col-sm-6 col-lg-3">
          <div class="card border bg-light stat-card">
            <div class="stat-icon bg-danger-subtle text-danger">
              <i class="bi bi-exclamation-octagon"></i>
            </div>
            <div>
              <div class="stat-value"><?= $previewBundle['invalid_count'] ?></div>
              <div class="stat-label">Invalid Rows</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Confirmation Form -->
      <form method="post" class="mb-4 p-3 bg-light rounded-3 border">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="commit_import">

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
          <div>
            <div class="form-check form-switch mb-1">
              <input class="form-check-input" type="checkbox" name="auto_categories" id="autoCategoriesSwitch" value="1" <?= $previewBundle['auto_categories'] ? 'checked' : '' ?>>
              <label class="form-check-label fw-semibold" for="autoCategoriesSwitch">
                Automatically create missing categories
              </label>
            </div>
            <div class="form-text text-muted small">
              If a product category doesn't exist yet, the system will automatically create it.
            </div>
          </div>

          <div class="d-flex align-items-center gap-2">
            <button type="submit" class="btn btn-success px-4 py-2" <?= ($previewBundle['ready_count'] + $previewBundle['update_count'] === 0) ? 'disabled' : '' ?>>
              <i class="bi bi-check2-circle me-1"></i> Confirm & Import <?= $previewBundle['ready_count'] + $previewBundle['update_count'] ?> Products
            </button>
            <a href="<?= e(admin_url('product_import.php?cancel=1')) ?>" class="btn btn-outline-secondary px-3 py-2">
              Cancel
            </a>
          </div>
        </div>
      </form>

      <!-- Preview Table -->
      <div class="table-responsive border rounded-3" style="max-height: 480px; overflow-y: auto;">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light sticky-top">
            <tr>
              <th style="width: 60px;">Row</th>
              <th>Product Name</th>
              <th>SKU</th>
              <th>Category</th>
              <th class="text-end">Price</th>
              <th class="text-end">Stock</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($previewBundle['rows'] as $r): ?>
              <tr class="<?= $r['action'] === 'error' ? 'table-danger' : '' ?>">
                <td class="font-monospace small text-muted">#<?= (int)$r['row'] ?></td>
                <td>
                  <div class="fw-bold"><?= e($r['name'] ?: '—') ?></div>
                  <?php if (!empty($r['brand'])): ?>
                    <span class="badge bg-light text-muted border" style="font-size: 0.68rem;"><?= e($r['brand']) ?></span>
                  <?php endif; ?>
                  <?php if (!empty($r['errors'])): ?>
                    <div class="text-danger small mt-1"><i class="bi bi-exclamation-circle me-1"></i><?= e(implode('; ', $r['errors'])) ?></div>
                  <?php endif; ?>
                </td>
                <td class="font-monospace small text-muted"><?= e($r['sku'] ?: '—') ?></td>
                <td>
                  <?php if (!empty($r['category'])): ?>
                    <?php if ($r['category_exists']): ?>
                      <span class="badge bg-secondary-subtle text-secondary"><?= e($r['category']) ?></span>
                    <?php else: ?>
                      <span class="badge bg-info-subtle text-info border border-info-subtle" title="Category will be auto-created">
                        <i class="bi bi-plus me-1"></i><?= e($r['category']) ?> (New)
                      </span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="text-muted small fst-italic">None</span>
                  <?php endif; ?>
                </td>
                <td class="text-end fw-semibold">
                  <?= format_price($r['price']) ?>
                  <?php if (!empty($r['sale_price'])): ?>
                    <div class="small text-danger">Sale: <?= format_price($r['sale_price']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="text-end font-monospace">
                  <?= (int)$r['stock'] ?>
                </td>
                <td>
                  <span class="badge <?= $r['status'] === 'active' ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?> text-uppercase" style="font-size: 0.7rem;">
                    <?= e($r['status']) ?>
                  </span>
                </td>
                <td>
                  <?php if ($r['action'] === 'create'): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                      <i class="bi bi-plus-lg me-1"></i>Create New
                    </span>
                  <?php elseif ($r['action'] === 'update'): ?>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                      <i class="bi bi-pencil me-1"></i>Update #<?= (int)$r['existing_id'] ?>
                    </span>
                  <?php elseif ($r['action'] === 'skip'): ?>
                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                      <i class="bi bi-skip-forward me-1"></i>Skip
                    </span>
                  <?php else: ?>
                    <span class="badge bg-danger text-white">
                      <i class="bi bi-x-circle me-1"></i>Invalid
                    </span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

<!-- ===================================================================== -->
<!-- STATE 3: UPLOAD CSV FORM & REFERENCE GUIDE                            -->
<!-- ===================================================================== -->
<?php else: ?>
  <div class="row g-4">
    <!-- Left Column: Upload Box & Options -->
    <div class="col-lg-7">
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 border-bottom">
          <h5 class="card-title mb-0 d-flex align-items-center gap-2">
            <i class="bi bi-file-earmark-arrow-up-fill text-primary"></i>
            <span>Upload Products CSV File</span>
          </h5>
        </div>
        <div class="card-body p-4">
          <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_csv">

            <!-- File Upload Drop Box -->
            <div class="mb-4">
              <label for="csvFileInput" class="form-label fw-semibold">Choose CSV Spreadsheet <span class="text-danger">*</span></label>
              <div class="p-4 border border-2 border-dashed rounded-3 text-center bg-light">
                <i class="bi bi-filetype-csv fs-1 text-primary mb-2 d-block"></i>
                <input type="file"
                       name="csv_file"
                       id="csvFileInput"
                       class="form-control form-control-lg mb-2"
                       accept=".csv, text/csv, text/plain"
                       required>
                <div class="form-text text-muted small">
                  Supports comma (<code>,</code>), semicolon (<code>;</code>), or tab (<code>\t</code>) delimited spreadsheets up to 20MB.
                </div>
              </div>
            </div>

            <!-- Import Settings Options -->
            <div class="card border bg-light mb-4">
              <div class="card-body">
                <h6 class="fw-bold mb-3 d-flex align-items-center gap-2">
                  <i class="bi bi-sliders text-primary"></i>
                  <span>Import Settings & Behavior</span>
                </h6>

                <!-- Duplicate Handling -->
                <div class="mb-3">
                  <label class="form-label fw-medium small text-uppercase text-muted">When Product Already Exists (SKU / Name match)</label>
                  <div class="form-check mb-1">
                    <input class="form-check-input" type="radio" name="duplicate_mode" id="dupModeUpdate" value="update" checked>
                    <label class="form-check-label" for="dupModeUpdate">
                      <strong>Update existing product:</strong> Refresh price, stock quantity, descriptions, and category.
                    </label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="duplicate_mode" id="dupModeSkip" value="skip">
                    <label class="form-check-label" for="dupModeSkip">
                      <strong>Skip existing product:</strong> Only create brand new products and leave current ones untouched.
                    </label>
                  </div>
                </div>

                <hr class="my-3">

                <!-- Default Status -->
                <div class="row g-3">
                  <div class="col-md-6">
                    <label for="defaultStatus" class="form-label fw-medium small text-uppercase text-muted">Default Status for New Items</label>
                    <select name="default_status" id="defaultStatus" class="form-select form-select-sm">
                      <option value="active" selected>Active (Visible on storefront)</option>
                      <option value="draft">Draft (Hidden until reviewed)</option>
                      <option value="inactive">Inactive</option>
                    </select>
                  </div>

                  <div class="col-md-6 d-flex align-items-end">
                    <div class="form-check mb-2">
                      <input class="form-check-input" type="checkbox" name="auto_categories" id="autoCatCheck" value="1" checked>
                      <label class="form-check-label small fw-medium" for="autoCatCheck">
                        Auto-create missing categories
                      </label>
                    </div>
                  </div>
                </div>

              </div>
            </div>

            <!-- Submit Button -->
            <div class="d-flex align-items-center gap-2">
              <button type="submit" class="btn btn-primary px-4 py-2">
                <i class="bi bi-file-earmark-check me-1"></i> Upload & Inspect Preview
              </button>
              <a href="<?= e(admin_url('products.php')) ?>" class="btn btn-outline-secondary px-4 py-2">
                Cancel
              </a>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Right Column: Instructions, Format Guidelines & Quick Sample -->
    <div class="col-lg-5">

      <!-- Quick Template Box -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 border-bottom">
          <h5 class="card-title mb-0 d-flex align-items-center gap-2">
            <i class="bi bi-lightning-charge-fill text-warning"></i>
            <span>Starter CSV Template</span>
          </h5>
        </div>
        <div class="card-body">
          <p class="small text-muted mb-3">
            Not sure how to structure your spreadsheet? Download our pre-filled template with working product examples:
          </p>
          <div class="d-grid mb-3">
            <a href="<?= e(admin_url('product_import.php?action=sample_csv')) ?>" class="btn btn-outline-primary d-flex align-items-center justify-content-center gap-2">
              <i class="bi bi-file-earmark-arrow-down-fill"></i>
              <span>Download Pre-filled Template (.CSV)</span>
            </a>
          </div>
          <div class="alert alert-info py-2 px-3 small mb-0">
            <i class="bi bi-info-circle me-1"></i> You can open and edit this template directly in Microsoft Excel, Google Sheets, or LibreOffice Calc.
          </div>
        </div>
      </div>

      <!-- Supported Columns Reference -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 border-bottom">
          <h5 class="card-title mb-0 d-flex align-items-center gap-2">
            <i class="bi bi-table text-primary"></i>
            <span>Supported CSV Columns</span>
          </h5>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0 small">
              <thead class="table-light">
                <tr>
                  <th>Column Header</th>
                  <th>Required?</th>
                  <th>Example Value</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td><code>name</code></td>
                  <td><span class="badge bg-danger-subtle text-danger">Required</span></td>
                  <td>Cotton Crewneck T-Shirt</td>
                </tr>
                <tr>
                  <td><code>price</code></td>
                  <td><span class="badge bg-danger-subtle text-danger">Required</span></td>
                  <td>1200.00</td>
                </tr>
                <tr>
                  <td><code>sale_price</code></td>
                  <td><span class="badge bg-light text-muted border">Optional</span></td>
                  <td>999.00</td>
                </tr>
                <tr>
                  <td><code>sku</code></td>
                  <td><span class="badge bg-light text-muted border">Optional</span></td>
                  <td>TSHIRT-COT-01</td>
                </tr>
                <tr>
                  <td><code>category</code></td>
                  <td><span class="badge bg-light text-muted border">Optional</span></td>
                  <td>Men, Women, Shoes...</td>
                </tr>
                <tr>
                  <td><code>stock</code></td>
                  <td><span class="badge bg-light text-muted border">Optional</span></td>
                  <td>50 (records stock ledger)</td>
                </tr>
                <tr>
                  <td><code>short_description</code></td>
                  <td><span class="badge bg-light text-muted border">Optional</span></td>
                  <td>Brief 1-2 line product summary</td>
                </tr>
                <tr>
                  <td><code>description</code></td>
                  <td><span class="badge bg-light text-muted border">Optional</span></td>
                  <td>Detailed full description</td>
                </tr>
                <tr>
                  <td><code>brand</code></td>
                  <td><span class="badge bg-light text-muted border">Optional</span></td>
                  <td>Brand / Manufacturer name</td>
                </tr>
                <tr>
                  <td><code>tags</code></td>
                  <td><span class="badge bg-light text-muted border">Optional</span></td>
                  <td>cotton, summer, casual</td>
                </tr>
                <tr>
                  <td><code>image</code></td>
                  <td><span class="badge bg-light text-muted border">Optional</span></td>
                  <td>https://example.com/pic.jpg</td>
                </tr>
                <tr>
                  <td><code>status</code></td>
                  <td><span class="badge bg-light text-muted border">Optional</span></td>
                  <td>active, draft, inactive</td>
                </tr>
                <tr>
                  <td><code>featured</code></td>
                  <td><span class="badge bg-light text-muted border">Optional</span></td>
                  <td>1 or 0 (or yes/no)</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
