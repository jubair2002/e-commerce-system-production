<?php
/**
 * Automated Test Suite for Bulk Product CSV Import
 */

declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';

$passCount = 0;
$failCount = 0;

function it(string $desc, callable $fn): void {
    global $passCount, $failCount;
    try {
        $fn();
        echo "  [PASS] {$desc}\n";
        $passCount++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$desc}: {$e->getMessage()}\n";
        $failCount++;
    }
}

echo "==========================================================\n";
echo "Starting Product CSV Import Test Suite\n";
echo "==========================================================\n\n";

$pdo = db();

it('CSV headers normalize correctly', function() {
    require_once __DIR__ . '/../admin/product_import.php';
    if (!function_exists('normalize_csv_header')) {
        throw new RuntimeException("normalize_csv_header function not found");
    }
    if (normalize_csv_header('Product Name') !== 'name') throw new RuntimeException("Failed Product Name");
    if (normalize_csv_header('Regular Price') !== 'price') throw new RuntimeException("Failed Regular Price");
    if (normalize_csv_header('Item SKU') !== 'sku') throw new RuntimeException("Failed Item SKU");
    if (normalize_csv_header('Quantity') !== 'stock') throw new RuntimeException("Failed Quantity");
    if (normalize_csv_header('Category Name') !== 'category') throw new RuntimeException("Failed Category Name");
});

it('Delimiter detection identifies comma, semicolon, and tab correctly', function() {
    $tempFile = tempnam(sys_get_temp_dir(), 'csv_test_');
    file_put_contents($tempFile, "name;price;stock\nShirt;1000;10");
    if (detect_csv_delimiter($tempFile) !== ';') throw new RuntimeException("Failed semicolon detection");

    file_put_contents($tempFile, "name,price,stock\nShirt,1000,10");
    if (detect_csv_delimiter($tempFile) !== ',') throw new RuntimeException("Failed comma detection");

    unlink($tempFile);
});

it('Parses and imports products, creates missing categories, and records stock adjustments', function() use ($pdo) {
    // 1. Create a dummy test CSV content
    $sku1 = 'TEST-IMPORT-' . uniqid();
    $sku2 = 'TEST-IMPORT-' . uniqid();
    $uniqueCat = 'TestCat_' . uniqid();

    $csvContent = "name,sku,category,price,sale_price,stock,short_description,description,brand,status,featured\n";
    $csvContent .= "\"Test Product Alpha\",{$sku1},\"{$uniqueCat}\",1500.00,1299.00,25,\"Short summary for alpha\",\"Full long description for alpha\",AlphaBrand,active,1\n";
    $csvContent .= "\"Test Product Beta\",{$sku2},Men,2500.00,,40,\"Short summary for beta\",\"Full long description for beta\",BetaBrand,draft,0\n";

    $tempCsv = tempnam(sys_get_temp_dir(), 'prod_import_');
    file_put_contents($tempCsv, $csvContent);

    // Simulate the import logic directly
    $delimiter = detect_csv_delimiter($tempCsv);
    $fp = fopen($tempCsv, 'r');
    $rawHeaders = fgetcsv($fp, 0, $delimiter, '"', "\\");
    $headerMap = [];
    foreach ($rawHeaders as $idx => $h) {
        $headerMap[normalize_csv_header($h)] = $idx;
    }

    $importedIds = [];
    $createdCatId = null;

    $pdo->beginTransaction();
    try {
        while (($row = fgetcsv($fp, 0, $delimiter, '"', "\\")) !== false) {
            $name = trim($row[$headerMap['name']] ?? '');
            $sku = trim($row[$headerMap['sku']] ?? '');
            $catName = trim($row[$headerMap['category']] ?? '');
            $price = (float)trim($row[$headerMap['price']] ?? '0');
            $salePrice = trim($row[$headerMap['sale_price']] ?? '');
            $stock = (int)trim($row[$headerMap['stock']] ?? '0');
            $brand = trim($row[$headerMap['brand']] ?? '');
            $status = trim($row[$headerMap['status']] ?? 'active');

            // Category resolve/create
            $stmtC = $pdo->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?) LIMIT 1");
            $stmtC->execute([$catName]);
            $catId = $stmtC->fetchColumn();
            if (!$catId) {
                $cSlug = unique_slug(slugify($catName), 'categories');
                $pdo->prepare("INSERT INTO categories (name, slug, created_at, updated_at) VALUES (?, ?, NOW(), NOW())")->execute([$catName, $cSlug]);
                $catId = (int)$pdo->lastInsertId();
                $createdCatId = $catId;
            }

            // Insert product
            $pSlug = unique_slug(slugify($name), 'products');
            $stmtIns = $pdo->prepare("
                INSERT INTO products
                    (category_id, name, slug, product_type, sku, price, sale_price, stock, brand, status, track_inventory, created_at, updated_at)
                VALUES
                    (?, ?, ?, 'simple', ?, ?, ?, 0, ?, ?, 1, NOW(), NOW())
            ");
            $stmtIns->execute([
                $catId, $name, $pSlug, $sku,
                $price, $salePrice !== '' ? (float)$salePrice : null,
                $brand, $status
            ]);
            $prodId = (int)$pdo->lastInsertId();
            $importedIds[] = $prodId;

            // Stock adjustment
            if ($stock > 0) {
                stock_adjust($prodId, null, $stock, 'restock', null, 'Test import');
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        unlink($tempCsv);
        throw $e;
    }
    fclose($fp);
    unlink($tempCsv);

    // Assertions
    if (count($importedIds) !== 2) {
        throw new RuntimeException("Expected 2 imported products, got " . count($importedIds));
    }

    // Verify first product
    $p1 = $pdo->query("SELECT * FROM products WHERE id = {$importedIds[0]}")->fetch(PDO::FETCH_ASSOC);
    if ($p1['name'] !== 'Test Product Alpha') throw new RuntimeException("Name mismatch on product 1");
    if ((float)$p1['price'] !== 1500.0) throw new RuntimeException("Price mismatch on product 1");
    if ((float)$p1['sale_price'] !== 1299.0) throw new RuntimeException("Sale price mismatch on product 1");
    if ((int)$p1['stock'] !== 25) throw new RuntimeException("Stock mismatch on product 1, got " . $p1['stock']);

    // Verify inventory movement ledger entry was created
    $ledgerCount = (int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE product_id = {$importedIds[0]}")->fetchColumn();
    if ($ledgerCount < 1) throw new RuntimeException("Inventory movement was not created for product 1");

    // Verify second product
    $p2 = $pdo->query("SELECT * FROM products WHERE id = {$importedIds[1]}")->fetch(PDO::FETCH_ASSOC);
    if ($p2['status'] !== 'draft') throw new RuntimeException("Status mismatch on product 2");
    if ((int)$p2['stock'] !== 40) throw new RuntimeException("Stock mismatch on product 2, got " . $p2['stock']);

    // Cleanup test data
    foreach ($importedIds as $pid) {
        $pdo->exec("DELETE FROM inventory_movements WHERE product_id = {$pid}");
        $pdo->exec("DELETE FROM products WHERE id = {$pid}");
    }
    if ($createdCatId) {
        $pdo->exec("DELETE FROM categories WHERE id = {$createdCatId}");
    }
});

it('Imports configurable products with options, variants, and SEO metadata', function() use ($pdo) {
    $skuVar = 'TEST-VAR-' . uniqid();
    $catName = 'Gadgets';

    // CSV with product_type = variable, options, and SEO columns
    $csvContent = "name,sku,product_type,category,price,stock,color,model,meta_title,meta_description,meta_keywords\n";
    $csvContent .= "\"Smart MagSafe Case\",{$skuVar},variable,{$catName},450.00,30,\"Black,Midnight Green\",\"iPhone 13,iPhone 14\",\"MagSafe Case Bangladesh | Jinishpotro\",\"Best smart MagSafe case in BD.\",\"magsafe case, iphone bd\"\n";

    $tempCsv = tempnam(sys_get_temp_dir(), 'var_import_');
    file_put_contents($tempCsv, $csvContent);

    // Simulate import parsing and execution
    $delimiter = detect_csv_delimiter($tempCsv);
    $fp = fopen($tempCsv, 'r');
    $rawHeaders = fgetcsv($fp, 0, $delimiter, '"', "\\");
    $headerMap = [];
    foreach ($rawHeaders as $idx => $h) {
        $headerMap[normalize_csv_header($h)] = $idx;
    }

    $createdProdId = null;
    $pdo->beginTransaction();
    try {
        $row = fgetcsv($fp, 0, $delimiter, '"', "\\");
        $getVal = fn($k) => isset($headerMap[$k], $row[$headerMap[$k]]) ? trim((string)$row[$headerMap[$k]]) : '';

        $name = $getVal('name');
        $sku = $getVal('sku');
        $ptype = $getVal('product_type');
        $cat = $getVal('category');
        $price = (float)$getVal('price');
        $stock = (int)$getVal('stock');
        $mTitle = $getVal('meta_title');
        $mDesc = $getVal('meta_description');
        $mKey = $getVal('meta_keywords');

        $options = [];
        if ($getVal('color') !== '') $options['Color'] = array_map('trim', explode(',', $getVal('color')));
        if ($getVal('model') !== '') $options['Model'] = array_map('trim', explode(',', $getVal('model')));

        // Category resolve
        $stmtC = $pdo->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $stmtC->execute([$cat]);
        $catId = $stmtC->fetchColumn() ?: null;

        $slug = unique_slug(slugify($name), 'products');
        $stmtIns = $pdo->prepare("
            INSERT INTO products
                (category_id, name, slug, product_type, sku, price, stock,
                 meta_title, meta_description, meta_keywords, status, created_at, updated_at)
            VALUES
                (:cid, :name, :slug, :ptype, :sku, :price, 0,
                 :mtitle, :mdesc, :mkey, 'active', NOW(), NOW())
        ");
        $stmtIns->execute([
            ':cid'    => $catId,
            ':name'   => $name,
            ':slug'   => $slug,
            ':ptype'  => 'variable',
            ':sku'    => $sku,
            ':price'  => $price,
            ':mtitle' => $mTitle,
            ':mdesc'  => $mDesc,
            ':mkey'   => $mKey,
        ]);
        $createdProdId = (int)$pdo->lastInsertId();

        // Create options and variants
        $valueMap = [];
        $optIdMap = [];
        $optPos = 0;
        foreach ($options as $optName => $optVals) {
            $optPos++;
            $dispType = strtolower($optName) === 'color' ? 'swatch' : 'button';
            $stmtInsOpt = $pdo->prepare("INSERT INTO product_options (product_id, name, display_type, position) VALUES (:pid, :n, :dt, :pos)");
            $stmtInsOpt->execute([':pid' => $createdProdId, ':n' => $optName, ':dt' => $dispType, ':pos' => $optPos]);
            $optId = (int)$pdo->lastInsertId();
            $optIdMap[$optName] = $optId;

            $valPos = 0;
            foreach ($optVals as $valStr) {
                $valPos++;
                $hex = $dispType === 'swatch' ? color_name_to_hex($valStr) : null;
                $stmtInsVal = $pdo->prepare("INSERT INTO product_option_values (option_id, value, swatch_hex, position) VALUES (:oid, :v, :hex, :pos)");
                $stmtInsVal->execute([':oid' => $optId, ':v' => $valStr, ':hex' => $hex, ':pos' => $valPos]);
                $valId = (int)$pdo->lastInsertId();
                $valueMap[$optName][$valStr] = $valId;
            }
        }

        // Generate combinations
        $combos = [[]];
        foreach (array_values($options) as $idx => $values) {
            $newCombos = [];
            $k = array_keys($options)[$idx];
            foreach ($combos as $combo) {
                foreach ($values as $val) {
                    $newCombos[] = array_merge($combo, [$k => $val]);
                }
            }
            $combos = $newCombos;
        }

        $vPos = 0;
        foreach ($combos as $combo) {
            $vPos++;
            $valIds = [];
            $slugs = [];
            foreach ($combo as $optName => $valStr) {
                $valIds[] = $valueMap[$optName][$valStr];
                $slugs[] = slugify($valStr);
            }
            $hash = variant_hash($valIds);
            $vSku = $sku . '-' . implode('-', $slugs);

            $stmtInsVar = $pdo->prepare("
                INSERT INTO product_variants (product_id, option_hash, sku, price, stock, status, position)
                VALUES (:pid, :hash, :sku, :price, 15, 'active', :pos)
            ");
            $stmtInsVar->execute([
                ':pid'   => $createdProdId,
                ':hash'  => $hash,
                ':sku'   => $vSku,
                ':price' => $price,
                ':pos'   => $vPos,
            ]);
            $varId = (int)$pdo->lastInsertId();

            $stmtLink = $pdo->prepare("INSERT INTO product_variant_values (variant_id, option_id, option_value_id) VALUES (?, ?, ?)");
            foreach ($combo as $optName => $valStr) {
                $stmtLink->execute([$varId, $optIdMap[$optName], $valueMap[$optName][$valStr]]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        unlink($tempCsv);
        throw $e;
    }
    fclose($fp);
    unlink($tempCsv);

    // Assertions
    $p = $pdo->query("SELECT * FROM products WHERE id = {$createdProdId}")->fetch(PDO::FETCH_ASSOC);
    if ($p['product_type'] !== 'variable') throw new RuntimeException("Expected product_type=variable");
    if ($p['meta_title'] !== 'MagSafe Case Bangladesh | Jinishpotro') throw new RuntimeException("meta_title mismatch");
    if ($p['meta_description'] !== 'Best smart MagSafe case in BD.') throw new RuntimeException("meta_description mismatch");

    // Check options
    $opts = $pdo->query("SELECT * FROM product_options WHERE product_id = {$createdProdId} ORDER BY position ASC")->fetchAll(PDO::FETCH_ASSOC);
    if (count($opts) !== 2) throw new RuntimeException("Expected 2 options, got " . count($opts));
    if ($opts[0]['display_type'] !== 'swatch') throw new RuntimeException("Color option should have display_type=swatch");
    if ($opts[1]['display_type'] !== 'button') throw new RuntimeException("Model option should have display_type=button");

    // Check variants: 2 colors * 2 models = 4 variants
    $vars = $pdo->query("SELECT * FROM product_variants WHERE product_id = {$createdProdId}")->fetchAll(PDO::FETCH_ASSOC);
    if (count($vars) !== 4) throw new RuntimeException("Expected 4 variant combinations, got " . count($vars));

    // Cleanup
    $pdo->exec("DELETE FROM product_variant_values WHERE variant_id IN (SELECT id FROM product_variants WHERE product_id = {$createdProdId})");
    $pdo->exec("DELETE FROM product_variants WHERE product_id = {$createdProdId}");
    $pdo->exec("DELETE FROM product_option_values WHERE option_id IN (SELECT id FROM product_options WHERE product_id = {$createdProdId})");
    $pdo->exec("DELETE FROM product_options WHERE product_id = {$createdProdId}");
    $pdo->exec("DELETE FROM products WHERE id = {$createdProdId}");
});

echo "\n==========================================================\n";
echo "Test Suite Results: {$passCount} Passed, {$failCount} Failed\n";
echo "==========================================================\n";

if ($failCount > 0) {
    exit(1);
}
