<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'editor');

$id        = (int)($_GET['id'] ?? 0);
$pageTitle = $id ? 'Edit product' : 'Add product';
$errors    = [];

$product = [
    'id' => 0, 'category_id' => null, 'name' => '', 'slug' => '', 'sku' => '',
    'product_type' => 'simple',
    'short_description' => '', 'description' => '',
    'price' => '0.00', 'sale_price' => '', 'stock' => '0',
    'image' => null, 'status' => 'active', 'featured' => 0,
    'track_inventory' => 1, 'allow_backorder' => 0, 'low_stock_threshold' => 5,
    'show_stock_count' => 1, 'weight_g' => '', 'brand' => '', 'tags' => '',
    'meta_title' => '', 'meta_description' => '', 'updated_at' => '',
];

$existingOptions  = [];
$existingVariants = [];
$existingImages   = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM products WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if (!($product = $stmt->fetch())) {
        flash('warning', 'Product not found.');
        admin_redirect('products.php');
    }

    $existingOptions  = product_options_get($id);
    $existingVariants = variants_get($id, false); // include inactive for admin editing

    $stmtImg = db()->prepare('SELECT * FROM product_images WHERE product_id = :id ORDER BY position ASC, id ASC');
    $stmtImg->execute([':id' => $id]);
    $existingImages = $stmtImg->fetchAll();
}

$categories = db()->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll();

// -------------------------------------------------------------
// POST Request Handler
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    // Concurrent edit detection
    if ($id) {
        $stmtCheck = db()->prepare('SELECT updated_at FROM products WHERE id = :id');
        $stmtCheck->execute([':id' => $id]);
        $currentUpdatedAt = $stmtCheck->fetchColumn();
        $postedUpdatedAt  = $_POST['original_updated_at'] ?? '';
        if ($currentUpdatedAt && $postedUpdatedAt && $currentUpdatedAt !== $postedUpdatedAt) {
            $errors[] = 'This product was modified by another user while you were editing it. Please refresh and review before saving.';
        }
    }

    $name              = trim((string)($_POST['name'] ?? ''));
    $slug              = trim((string)($_POST['slug'] ?? ''));
    $sku               = trim((string)($_POST['sku']  ?? ''));
    $productType       = in_array($_POST['product_type'] ?? '', ['simple', 'variable'], true) ? $_POST['product_type'] : 'simple';
    $categoryId        = (int)($_POST['category_id']  ?? 0);
    $price             = (string)($_POST['price']     ?? '0');
    $salePrice         = trim((string)($_POST['sale_price'] ?? ''));
    $stock             = (int)($_POST['stock']        ?? 0);
    $shortDescription  = trim((string)($_POST['short_description'] ?? ''));
    $description       = trim((string)($_POST['description']       ?? ''));
    $status            = in_array($_POST['status'] ?? '', ['active', 'inactive', 'draft'], true) ? $_POST['status'] : 'active';
    $featured          = isset($_POST['featured']) ? 1 : 0;

    $trackInventory    = isset($_POST['track_inventory']) ? 1 : 0;
    $allowBackorder    = isset($_POST['allow_backorder']) ? 1 : 0;
    $lowStockThreshold = max(0, (int)($_POST['low_stock_threshold'] ?? 5));
    $showStockCount    = isset($_POST['show_stock_count']) ? 1 : 0;
    $weightG           = ($_POST['weight_g'] !== '' && is_numeric($_POST['weight_g'])) ? (int)$_POST['weight_g'] : null;
    $brand             = trim((string)($_POST['brand'] ?? ''));
    $tags              = trim((string)($_POST['tags'] ?? ''));
    $metaTitle         = trim((string)($_POST['meta_title'] ?? ''));
    $metaDesc          = trim((string)($_POST['meta_description'] ?? ''));

    // Validation
    if ($name === '') {
        $errors[] = 'Product name is required.';
    }
    if (!is_numeric($price) || (float)$price < 0) {
        $errors[] = 'Price must be a non-negative number.';
    }
    if ($salePrice !== '' && (!is_numeric($salePrice) || (float)$salePrice < 0)) {
        $errors[] = 'Sale price must be a non-negative number (or blank).';
    }
    if ($salePrice !== '' && is_numeric($salePrice) && is_numeric($price) && (float)$salePrice >= (float)$price) {
        $errors[] = 'Sale price must be less than the regular price.';
    }

    // SKU uniqueness across products
    if ($sku !== '') {
        $stmt = db()->prepare('SELECT id FROM products WHERE sku = :s AND id <> :id');
        $stmt->execute([':s' => $sku, ':id' => $id]);
        if ($stmt->fetch()) {
            $errors[] = 'The product SKU is already in use by another product.';
        }
    }

    // Decode Options and Variants JSON for Variable Products
    $postedOptions  = [];
    $postedVariants = [];
    if ($productType === 'variable') {
        $optionsJson = $_POST['options_json'] ?? '[]';
        $variantsJson = $_POST['variants_json'] ?? '[]';

        $postedOptions  = json_decode($optionsJson, true) ?: [];
        $postedVariants = json_decode($variantsJson, true) ?: [];

        if (empty($postedOptions)) {
            $errors[] = 'Products with options must define at least one option.';
        } elseif (count($postedOptions) > 3) {
            $errors[] = 'A maximum of 3 options is allowed per product.';
        }

        if (empty($postedVariants)) {
            $errors[] = 'Please generate product variants for the defined options.';
        } elseif (count($postedVariants) > 100) {
            $errors[] = 'A maximum of 100 variant combinations is allowed.';
        }

        // Validate option values and case-insensitive duplicates
        foreach ($postedOptions as $opt) {
            $optName = trim((string)($opt['name'] ?? ''));
            if ($optName === '') {
                $errors[] = 'All options must have a name.';
            }
            $valSet = [];
            foreach (($opt['values'] ?? []) as $val) {
                $vStr = trim((string)($val['value'] ?? ''));
                if ($vStr === '') continue;
                $vLower = mb_strtolower($vStr, 'UTF-8');
                if (isset($valSet[$vLower])) {
                    $errors[] = "Duplicate option value \"{$vStr}\" in option \"{$optName}\".";
                }
                $valSet[$vLower] = true;
            }
        }

        // Validate variant SKUs
        $variantSkuSet = [];
        foreach ($postedVariants as $v) {
            $vSku = trim((string)($v['sku'] ?? ''));
            if ($vSku !== '') {
                $vSkuLower = strtolower($vSku);
                if (isset($variantSkuSet[$vSkuLower])) {
                    $errors[] = "Duplicate SKU \"{$vSku}\" found across variants.";
                }
                $variantSkuSet[$vSkuLower] = true;

                // Check against other products in DB
                $stmtCheckSku = db()->prepare(
                    "SELECT id FROM products WHERE sku = :s AND id <> :pid
                     UNION
                     SELECT product_id FROM product_variants WHERE sku = :s AND product_id <> :pid"
                );
                $stmtCheckSku->execute([':s' => $vSku, ':pid' => $id]);
                if ($stmtCheckSku->fetch()) {
                    $errors[] = "The variant SKU \"{$vSku}\" is already used by another product or variant.";
                }
            }
        }
    }

    // -------------------------------------------------------------
    // Save in Database Transaction
    // -------------------------------------------------------------
    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $finalSlug = $slug !== '' ? slugify($slug) : slugify($name);
            $finalSlug = unique_slug($finalSlug, 'products', $id);

            $primaryImage = $product['image'] ?? null;

            // Handle primary image upload if file selected
            $uploadErr = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
            $uploadedPrimaryFile = null;
            if (!empty($_FILES['image']['name']) && $uploadErr === UPLOAD_ERR_OK) {
                $tmp  = $_FILES['image']['tmp_name'];
                $size = (int)$_FILES['image']['size'];
                $mime = function_exists('mime_content_type') ? mime_content_type($tmp) : null;
                $okMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

                if (isset($okMimes[$mime]) && $size <= 4 * 1024 * 1024) {
                    $dir = UPLOADS_PATH . '/products';
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0775, true);
                    }
                    $newFileName = 'p_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $okMimes[$mime];
                    if (move_uploaded_file($tmp, $dir . '/' . $newFileName)) {
                        $primaryImage = $newFileName;
                        $uploadedPrimaryFile = $dir . '/' . $newFileName;
                    }
                }
            }

            // Insert / Update Product Row
            $productData = [
                ':cid'   => $categoryId > 0 ? $categoryId : null,
                ':name'  => $name,
                ':slug'  => $finalSlug,
                ':ptype' => $productType,
                ':sku'   => $sku !== '' ? $sku : null,
                ':sd'    => $shortDescription !== '' ? $shortDescription : null,
                ':d'     => $description !== '' ? $description : null,
                ':price' => number_format((float)$price, 2, '.', ''),
                ':sp'    => $salePrice !== '' ? number_format((float)$salePrice, 2, '.', '') : null,
                ':stock' => ($productType === 'simple') ? $stock : 0,
                ':img'   => $primaryImage,
                ':st'    => $status,
                ':feat'  => $featured,
                ':ti'    => $trackInventory,
                ':ab'    => $allowBackorder,
                ':lst'   => $lowStockThreshold,
                ':ssc'   => $showStockCount,
                ':wg'    => $weightG,
                ':brand' => $brand !== '' ? $brand : null,
                ':tags'  => $tags !== '' ? $tags : null,
                ':mt'    => $metaTitle !== '' ? $metaTitle : null,
                ':md'    => $metaDesc !== '' ? $metaDesc : null,
            ];

            $oldStock = 0;
            if ($id) {
                $stmtOldStock = $pdo->prepare("SELECT stock, product_type FROM products WHERE id = :id");
                $stmtOldStock->execute([':id' => $id]);
                $oldProdRow = $stmtOldStock->fetch();
                $oldStock = (int)($oldProdRow['stock'] ?? 0);

                $productData[':id'] = $id;
                $stmtUpdate = $pdo->prepare(
                    "UPDATE products SET
                        category_id = :cid, name = :name, slug = :slug, product_type = :ptype, sku = :sku,
                        short_description = :sd, description = :d, price = :price, sale_price = :sp,
                        stock = :stock, image = :img, status = :st, featured = :feat,
                        track_inventory = :ti, allow_backorder = :ab, low_stock_threshold = :lst,
                        show_stock_count = :ssc, weight_g = :wg, brand = :brand, tags = :tags,
                        meta_title = :mt, meta_description = :md
                     WHERE id = :id"
                );
                $stmtUpdate->execute($productData);
            } else {
                $stmtInsert = $pdo->prepare(
                    "INSERT INTO products
                        (category_id, name, slug, product_type, sku, short_description, description,
                         price, sale_price, stock, image, status, featured,
                         track_inventory, allow_backorder, low_stock_threshold, show_stock_count,
                         weight_g, brand, tags, meta_title, meta_description)
                     VALUES
                        (:cid, :name, :slug, :ptype, :sku, :sd, :d,
                         :price, :sp, :stock, :img, :st, :feat,
                         :ti, :ab, :lst, :ssc, :wg, :brand, :tags, :mt, :md)"
                );
                $stmtInsert->execute($productData);
                $id = (int)$pdo->lastInsertId();
            }

            // Generate responsive WebP variants for newly uploaded primary image
            if ($uploadedPrimaryFile && $id) {
                product_generate_responsive_variants($id, $uploadedPrimaryFile, $primaryImage);
            }

            // Simple product stock adjustment ledger entry
            if ($productType === 'simple') {
                $delta = $stock - $oldStock;
                if ($delta !== 0) {
                    stock_adjust($id, null, $delta, 'manual_adjust', null, 'Product edit form stock update');
                }
                // If switching from variable -> simple, keep existing variants inactive
                $pdo->prepare("UPDATE product_variants SET status = 'inactive' WHERE product_id = :pid")->execute([':pid' => $id]);
            }

            // ---------------------------------------------------------
            // Variable Product: Options, Values, and Variants
            // ---------------------------------------------------------
            if ($productType === 'variable') {
                // Fetch existing options
                $existingOptRows = $pdo->prepare("SELECT id, name FROM product_options WHERE product_id = :pid");
                $existingOptRows->execute([':pid' => $id]);
                $existingOptMap = [];
                while ($r = $existingOptRows->fetch()) {
                    $existingOptMap[strtolower($r['name'])] = (int)$r['id'];
                }

                $activeOptIds = [];
                $valueMap = []; // [option_name => [value_string => value_id]]

                $stmtOptIns = $pdo->prepare("INSERT INTO product_options (product_id, name, display_type, position) VALUES (:pid, :n, :dt, :pos)");
                $stmtOptUpd = $pdo->prepare("UPDATE product_options SET name = :n, display_type = :dt, position = :pos WHERE id = :id");

                $stmtValIns = $pdo->prepare("INSERT INTO product_option_values (option_id, value, swatch_hex, position) VALUES (:oid, :v, :hex, :pos)");
                $stmtValUpd = $pdo->prepare("UPDATE product_option_values SET value = :v, swatch_hex = :hex, position = :pos WHERE id = :id");

                foreach ($postedOptions as $optPos => $opt) {
                    $optName = trim((string)($opt['name'] ?? ''));
                    $optType = in_array($opt['display_type'] ?? '', ['button', 'dropdown', 'swatch', 'radio'], true) ? $opt['display_type'] : 'button';
                    $optId   = !empty($opt['id']) ? (int)$opt['id'] : ($existingOptMap[strtolower($optName)] ?? 0);

                    if ($optId > 0) {
                        $stmtOptUpd->execute([':n' => $optName, ':dt' => $optType, ':pos' => $optPos + 1, ':id' => $optId]);
                    } else {
                        $stmtOptIns->execute([':pid' => $id, ':n' => $optName, ':dt' => $optType, ':pos' => $optPos + 1]);
                        $optId = (int)$pdo->lastInsertId();
                    }
                    $activeOptIds[] = $optId;

                    // Existing values for this option
                    $existingValRows = $pdo->prepare("SELECT id, value FROM product_option_values WHERE option_id = :oid");
                    $existingValRows->execute([':oid' => $optId]);
                    $existingValMap = [];
                    while ($vr = $existingValRows->fetch()) {
                        $existingValMap[strtolower($vr['value'])] = (int)$vr['id'];
                    }

                    $activeValIds = [];
                    foreach (($opt['values'] ?? []) as $valPos => $val) {
                        $valStr = trim((string)($val['value'] ?? ''));
                        if ($valStr === '') continue;
                        $hex = (!empty($val['swatch_hex']) && preg_match('/^#[0-9a-fA-F]{6}$/', $val['swatch_hex'])) ? $val['swatch_hex'] : null;
                        $valId = !empty($val['id']) ? (int)$val['id'] : ($existingValMap[strtolower($valStr)] ?? 0);

                        if ($valId > 0) {
                            $stmtValUpd->execute([':v' => $valStr, ':hex' => $hex, ':pos' => $valPos + 1, ':id' => $valId]);
                        } else {
                            $stmtValIns->execute([':oid' => $optId, ':v' => $valStr, ':hex' => $hex, ':pos' => $valPos + 1]);
                            $valId = (int)$pdo->lastInsertId();
                        }
                        $activeValIds[] = $valId;
                        $valueMap[strtolower($optName)][strtolower($valStr)] = [
                            'option_id' => $optId,
                            'value_id'  => $valId,
                        ];
                    }

                    // Delete removed values for this option
                    if ($activeValIds) {
                        $inVals = implode(',', $activeValIds);
                        $pdo->prepare("DELETE FROM product_option_values WHERE option_id = :oid AND id NOT IN ($inVals)")->execute([':oid' => $optId]);
                    }
                }

                // Delete removed options
                if ($activeOptIds) {
                    $inOpts = implode(',', $activeOptIds);
                    $pdo->prepare("DELETE FROM product_options WHERE product_id = :pid AND id NOT IN ($inOpts)")->execute([':pid' => $id]);
                }

                // -----------------------------------------------------
                // Manage Variants
                // -----------------------------------------------------
                $existingDbVariants = [];
                $stmtVarCur = $pdo->prepare("SELECT id, option_hash, stock FROM product_variants WHERE product_id = :pid");
                $stmtVarCur->execute([':pid' => $id]);
                while ($vRow = $stmtVarCur->fetch()) {
                    $existingDbVariants[$vRow['option_hash']] = [
                        'id'    => (int)$vRow['id'],
                        'stock' => (int)$vRow['stock'],
                    ];
                }

                $activeHashes = [];

                $stmtVarIns = $pdo->prepare(
                    "INSERT INTO product_variants
                        (product_id, option_hash, sku, barcode, price, sale_price, stock, weight_g, image, status, position)
                     VALUES
                        (:pid, :hash, :sku, :bc, :pr, :sp, 0, :wg, :img, :st, :pos)"
                );

                $stmtVarUpd = $pdo->prepare(
                    "UPDATE product_variants SET
                        sku = :sku, barcode = :bc, price = :pr, sale_price = :sp, weight_g = :wg,
                        image = :img, status = :st, position = :pos
                     WHERE id = :id"
                );

                $stmtLinkIns = $pdo->prepare(
                    "INSERT IGNORE INTO product_variant_values (variant_id, option_id, option_value_id)
                     VALUES (:vid, :oid, :ovid)"
                );

                foreach ($postedVariants as $vPos => $v) {
                    $combo = $v['combo'] ?? [];
                    $valIds = [];
                    $linkPairs = [];

                    foreach ($combo as $c) {
                        $optNameLower = strtolower(trim((string)($c['option_name'] ?? '')));
                        $valStrLower  = strtolower(trim((string)($c['value'] ?? '')));
                        if (isset($valueMap[$optNameLower][$valStrLower])) {
                            $mapped = $valueMap[$optNameLower][$valStrLower];
                            $valIds[] = $mapped['value_id'];
                            $linkPairs[] = $mapped;
                        }
                    }

                    if (empty($valIds)) {
                        continue;
                    }

                    $optionHash = variant_hash($valIds);
                    $activeHashes[] = $optionHash;

                    $vSku    = trim((string)($v['sku'] ?? '')) ?: null;
                    $vBc     = trim((string)($v['barcode'] ?? '')) ?: null;
                    $vPrice  = ($v['price'] !== '' && is_numeric($v['price'])) ? number_format((float)$v['price'], 2, '.', '') : null;
                    $vSale   = ($v['sale_price'] !== '' && is_numeric($v['sale_price'])) ? number_format((float)$v['sale_price'], 2, '.', '') : null;
                    $vWeight = ($v['weight_g'] !== '' && is_numeric($v['weight_g'])) ? (int)$v['weight_g'] : null;
                    $vImg    = !empty($v['image']) ? $v['image'] : null;
                    $vStatus = ($v['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
                    $targetStock = max(0, (int)($v['stock'] ?? 0));

                    $variantId = 0;
                    $currentDbStock = 0;

                    if (isset($existingDbVariants[$optionHash])) {
                        $variantId = $existingDbVariants[$optionHash]['id'];
                        $currentDbStock = $existingDbVariants[$optionHash]['stock'];

                        $stmtVarUpd->execute([
                            ':sku' => $vSku, ':bc' => $vBc, ':pr' => $vPrice, ':sp' => $vSale,
                            ':wg'  => $vWeight, ':img' => $vImg, ':st' => $vStatus,
                            ':pos' => $vPos + 1, ':id' => $variantId
                        ]);
                    } else {
                        $stmtVarIns->execute([
                            ':pid'  => $id, ':hash' => $optionHash, ':sku' => $vSku, ':bc' => $vBc,
                            ':pr'   => $vPrice, ':sp' => $vSale, ':wg' => $vWeight,
                            ':img'  => $vImg, ':st' => $vStatus, ':pos' => $vPos + 1
                        ]);
                        $variantId = (int)$pdo->lastInsertId();
                        $currentDbStock = 0;

                        // Insert variant value links
                        foreach ($linkPairs as $lp) {
                            $stmtLinkIns->execute([
                                ':vid'  => $variantId,
                                ':oid'  => $lp['option_id'],
                                ':ovid' => $lp['value_id'],
                            ]);
                        }
                    }

                    // Stock adjust delta ledger entry
                    $stockDelta = $targetStock - $currentDbStock;
                    if ($stockDelta !== 0) {
                        stock_adjust($id, $variantId, $stockDelta, 'manual_adjust', null, 'Product form variant stock update');
                    }
                }

                // Deactivate variants no longer in combination list (never hard delete if existing orders rely on it)
                if ($activeHashes) {
                    $hashPlaceholders = implode(',', array_fill(0, count($activeHashes), '?'));
                    $deactParams = array_merge([$id], $activeHashes);
                    $stmtDeact = $pdo->prepare(
                        "UPDATE product_variants SET status = 'inactive'
                         WHERE product_id = ? AND option_hash NOT IN ($hashPlaceholders)"
                    );
                    $stmtDeact->execute($deactParams);
                }
            }

            // ---------------------------------------------------------
            // Product Images Gallery Multi-Upload
            // ---------------------------------------------------------
            if (!empty($_FILES['gallery_images']['name']) && is_array($_FILES['gallery_images']['name'])) {
                $dir = UPLOADS_PATH . '/products';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }

                $stmtImgIns = $pdo->prepare(
                    "INSERT INTO product_images (product_id, filename, alt_text, position, is_primary)
                     VALUES (:pid, :fn, :alt, :pos, :ip)"
                );

                $curPosStmt = $pdo->prepare("SELECT COALESCE(MAX(position), 0) FROM product_images WHERE product_id = :pid");
                $curPosStmt->execute([':pid' => $id]);
                $nextImgPos = (int)$curPosStmt->fetchColumn() + 1;

                foreach ($_FILES['gallery_images']['name'] as $k => $fName) {
                    if (empty($fName) || ($_FILES['gallery_images']['error'][$k] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                        continue;
                    }
                    $tmp  = $_FILES['gallery_images']['tmp_name'][$k];
                    $size = (int)$_FILES['gallery_images']['size'][$k];
                    $mime = function_exists('mime_content_type') ? mime_content_type($tmp) : null;
                    $okMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

                    if (isset($okMimes[$mime]) && $size <= 4 * 1024 * 1024) {
                        $newFileName = 'p_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $okMimes[$mime];
                        if (move_uploaded_file($tmp, $dir . '/' . $newFileName)) {
                            product_generate_responsive_variants($id, $dir . '/' . $newFileName, $newFileName);
                            $stmtImgIns->execute([
                                ':pid' => $id,
                                ':fn'  => $newFileName,
                                ':alt' => $name,
                                ':pos' => $nextImgPos++,
                                ':ip'  => 0,
                            ]);
                            if (empty($primaryImage)) {
                                $primaryImage = $newFileName;
                                $pdo->prepare("UPDATE products SET image = :img WHERE id = :id")->execute([':img' => $newFileName, ':id' => $id]);
                            }
                        }
                    }
                }
            }

            // Update primary image selection from gallery if chosen
            if (!empty($_POST['selected_primary_image'])) {
                $selImg = trim($_POST['selected_primary_image']);
                $pdo->prepare("UPDATE product_images SET is_primary = IF(filename = :fn, 1, 0) WHERE product_id = :pid")->execute([':fn' => $selImg, ':pid' => $id]);
                $pdo->prepare("UPDATE products SET image = :img WHERE id = :id")->execute([':img' => $selImg, ':id' => $id]);
            }

            $pdo->commit();

            log_activity('product.save', "Saved product {$name} (type: {$productType}, variants: " . count($postedVariants) . ")");
            flash('success', $id ? 'Product saved successfully.' : 'Product created successfully.');
            admin_redirect('products.php');
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Failed to save product: ' . $e->getMessage();
        }
    }

    $product = array_merge($product, [
        'name' => $name, 'slug' => $slug, 'sku' => $sku, 'product_type' => $productType,
        'category_id' => $categoryId ?: null,
        'short_description' => $shortDescription, 'description' => $description,
        'price' => $price, 'sale_price' => $salePrice, 'stock' => $stock,
        'status' => $status, 'featured' => $featured,
        'track_inventory' => $trackInventory, 'allow_backorder' => $allowBackorder,
        'low_stock_threshold' => $lowStockThreshold, 'show_stock_count' => $showStockCount,
        'weight_g' => $weightG, 'brand' => $brand, 'tags' => $tags,
        'meta_title' => $metaTitle, 'meta_description' => $metaDesc,
    ]);
}

include __DIR__ . '/includes/header.php';
?>

<form method="post" id="product_form" enctype="multipart/form-data" autocomplete="off" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="original_updated_at" value="<?= e($product['updated_at'] ?? '') ?>">
  <input type="hidden" name="options_json" id="options_json" value="">
  <input type="hidden" name="variants_json" id="variants_json" value="">

  <datalist id="presets_datalist"></datalist>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h4 class="mb-0"><?= $id ? 'Edit product' : 'Add product' ?></h4>
      <small class="text-muted"><?= $id ? 'Manage product details, inventory, and combinations' : 'Create a single product or product with options' ?></small>
    </div>
    <div class="d-flex gap-2">
      <a href="<?= e(admin_url('products.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-check2"></i> <?= $id ? 'Save changes' : 'Create product' ?>
      </button>
    </div>
  </div>

  <?php foreach ($errors as $err): ?>
    <div class="alert alert-danger py-2 mb-3">
      <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= e($err) ?>
    </div>
  <?php endforeach; ?>

  <div class="row g-3">

    <!-- Left Main Column -->
    <div class="col-lg-8">

      <!-- Product Type Selector Card -->
      <div class="card mb-3 border-indigo shadow-sm">
        <div class="card-body">
          <label class="form-label fw-bold mb-2">Product Type</label>
          <div class="row g-2">
            <div class="col-sm-6">
              <label class="card p-3 h-100 border text-center cursor-pointer shadow-sm type-choice-card">
                <input type="radio" name="product_type" id="type_simple" value="simple"
                       class="form-check-input mb-2 mx-auto" <?= ($product['product_type'] ?? 'simple') === 'simple' ? 'checked' : '' ?>>
                <div class="fw-bold"><i class="bi bi-box me-1"></i> Simple product</div>
                <small class="text-muted">A standalone product with a single stock count and SKU.</small>
              </label>
            </div>
            <div class="col-sm-6">
              <label class="card p-3 h-100 border text-center cursor-pointer shadow-sm type-choice-card">
                <input type="radio" name="product_type" id="type_variable" value="variable"
                       class="form-check-input mb-2 mx-auto" <?= ($product['product_type'] ?? 'simple') === 'variable' ? 'checked' : '' ?>>
                <div class="fw-bold"><i class="bi bi-diagram-3 me-1"></i> Product with options</div>
                <small class="text-muted">Multiple combinations (Size, Color, etc.) with per-variant stock, price, and SKU.</small>
              </label>
            </div>
          </div>
        </div>
      </div>

      <!-- General Product Information -->
      <div class="card mb-3 shadow-sm">
        <div class="card-body">
          <h5 class="card-title mb-3"><i class="bi bi-info-circle me-1"></i> Basic information</h5>

          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" value="<?= e($product['name']) ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Base SKU</label>
              <input type="text" name="sku" id="sku" class="form-control"
                     value="<?= e($product['sku'] ?? '') ?>" placeholder="e.g. TEE-001">
            </div>
            <div class="col-md-8">
              <label class="form-label">Slug <small class="text-muted">(auto if blank)</small></label>
              <input type="text" name="slug" class="form-control"
                     value="<?= e($product['slug']) ?>" placeholder="auto-generated">
            </div>
            <div class="col-md-4">
              <label class="form-label">Category</label>
              <select name="category_id" class="form-select">
                <option value="0">— None —</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c['id'] ?>" <?= (int)($product['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                    <?= e($c['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Short description</label>
              <textarea name="short_description" class="form-control" rows="2" maxlength="500"><?= e($product['short_description'] ?? '') ?></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Full description</label>
              <textarea name="description" class="form-control" rows="5"><?= e($product['description'] ?? '') ?></textarea>
            </div>
          </div>
        </div>
      </div>

      <!-- Pricing & Base Stock Card -->
      <div class="card mb-3 shadow-sm">
        <div class="card-body">
          <h5 class="card-title mb-3"><i class="bi bi-tag me-1"></i> Pricing &amp; Stock</h5>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Price (<?= e(setting('currency_symbol', '$')) ?>) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" min="0" name="price" id="price" class="form-control"
                     value="<?= e($product['price']) ?>" required>
              <small class="text-muted" id="base_price_hint" style="display:none;">Used as default for variants.</small>
            </div>
            <div class="col-md-4">
              <label class="form-label">Sale price <small class="text-muted">(optional)</small></label>
              <input type="number" step="0.01" min="0" name="sale_price" id="sale_price" class="form-control"
                     value="<?= e($product['sale_price'] ?? '') ?>">
            </div>
            <div class="col-md-4" id="simple_stock_wrapper">
              <label class="form-label">Stock quantity</label>
              <input type="number" step="1" min="0" name="stock" class="form-control"
                     value="<?= e((string)($product['stock'] ?? 0)) ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- Options Builder Card (shown only for variable products) -->
      <div class="card mb-3 shadow-sm border-indigo" id="options_builder_card" style="display:none;">
        <div class="card-header bg-body-tertiary d-flex justify-content-between align-items-center py-2">
          <div class="fw-semibold"><i class="bi bi-sliders me-1"></i> Product options (Size, Color, etc.)</div>
          <button type="button" class="btn btn-sm btn-primary" id="add_option_btn">
            <i class="bi bi-plus-lg"></i> Add option
          </button>
        </div>
        <div class="card-body">
          <div id="options_container"></div>
          <div class="mt-3 text-end">
            <button type="button" class="btn btn-success" id="generate_combos_btn">
              <i class="bi bi-lightning-charge"></i> Generate combinations
            </button>
          </div>
        </div>
      </div>

      <!-- Variants Table Card (shown only for variable products) -->
      <div class="card mb-3 shadow-sm border-indigo" id="variants_card" style="display:none;">
        <div class="card-header bg-body-tertiary d-flex justify-content-between align-items-center py-2">
          <div class="fw-semibold">
            <i class="bi bi-grid-3x3-gap me-1"></i> Variants
            <span class="badge bg-secondary ms-1" id="variants_count_badge">0 variants</span>
          </div>
        </div>
        <div class="card-body p-0">

          <!-- Bulk Edit Bar -->
          <div class="p-2 border-bottom bg-light d-flex flex-wrap align-items-center gap-2">
            <span class="small fw-semibold text-muted ms-1">Bulk edit:</span>
            <div class="input-group input-group-sm" style="max-width:140px;">
              <input type="number" step="0.01" min="0" class="form-control" id="bulk_price_input" placeholder="Price">
              <button class="btn btn-outline-secondary" type="button" id="bulk_set_price_btn">Set</button>
            </div>
            <div class="input-group input-group-sm" style="max-width:140px;">
              <input type="number" min="0" class="form-control" id="bulk_stock_input" placeholder="Stock">
              <button class="btn btn-outline-secondary" type="button" id="bulk_set_stock_btn">Set</button>
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary" id="bulk_add_10_stock_btn">
              +10 Stock
            </button>
          </div>

          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0" id="variants_table">
              <thead class="table-light">
                <tr>
                  <th style="width:40px;" class="text-center">
                    <input type="checkbox" class="form-check-input" id="select_all_variants" title="Select all">
                  </th>
                  <th style="width:50px;" class="text-center">Status</th>
                  <th>Combination</th>
                  <th>SKU</th>
                  <th>Price override</th>
                  <th>Sale price</th>
                  <th>Stock</th>
                  <th>Weight</th>
                  <th>Image</th>
                </tr>
              </thead>
              <tbody id="variants_tbody">
                <tr><td colspan="9" class="text-center text-muted py-3">No variants generated yet.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Inventory & Advanced Attributes Card -->
      <div class="card mb-3 shadow-sm">
        <div class="card-body">
          <h5 class="card-title mb-3"><i class="bi bi-box-seam me-1"></i> Inventory rules &amp; attributes</h5>
          <div class="row g-3">
            <div class="col-md-6">
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" name="track_inventory" id="track_inventory" value="1"
                       <?= !empty($product['track_inventory']) ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="track_inventory">Track inventory for this product</label>
              </div>
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" name="allow_backorder" id="allow_backorder" value="1"
                       <?= !empty($product['allow_backorder']) ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="allow_backorder">Allow backorders when out of stock</label>
              </div>
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" name="show_stock_count" id="show_stock_count" value="1"
                       <?= !empty($product['show_stock_count']) ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="show_stock_count">Display stock count on storefront</label>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Low stock threshold</label>
              <input type="number" min="0" name="low_stock_threshold" class="form-control"
                     value="<?= e((string)($product['low_stock_threshold'] ?? 5)) ?>">
              <div class="form-text">Alerts are sent when stock reaches or drops below this count.</div>
            </div>

            <div class="col-md-4">
              <label class="form-label">Weight (grams)</label>
              <input type="number" min="0" name="weight_g" class="form-control"
                     value="<?= e((string)($product['weight_g'] ?? '')) ?>" placeholder="e.g. 250">
            </div>
            <div class="col-md-4">
              <label class="form-label">Brand</label>
              <input type="text" name="brand" class="form-control"
                     value="<?= e($product['brand'] ?? '') ?>" placeholder="e.g. Nike, Apple">
            </div>
            <div class="col-md-4">
              <label class="form-label">Tags <small class="text-muted">(comma-separated)</small></label>
              <input type="text" name="tags" class="form-control"
                     value="<?= e($product['tags'] ?? '') ?>" placeholder="cotton, t-shirt, summer">
            </div>
          </div>
        </div>
      </div>

      <!-- SEO Meta Details Card -->
      <div class="card mb-3 shadow-sm">
        <div class="card-body">
          <h5 class="card-title mb-3"><i class="bi bi-search me-1"></i> Search engine optimization (SEO)</h5>
          <div class="mb-3">
            <label class="form-label">Meta title</label>
            <input type="text" name="meta_title" class="form-control" maxlength="160"
                   value="<?= e($product['meta_title'] ?? '') ?>" placeholder="Leave blank to use product name">
          </div>
          <div>
            <label class="form-label">Meta description</label>
            <textarea name="meta_description" class="form-control" rows="2" maxlength="255"><?= e($product['meta_description'] ?? '') ?></textarea>
          </div>
        </div>
      </div>

    </div>

    <!-- Right Sidebar Column -->
    <div class="col-lg-4">

      <!-- Status Card -->
      <div class="card mb-3 shadow-sm">
        <div class="card-body">
          <h6 class="card-title">Status &amp; Visibility</h6>
          <select name="status" class="form-select mb-3">
            <?php foreach (['active', 'draft', 'inactive'] as $s): ?>
              <option value="<?= $s ?>" <?= ($product['status'] ?? 'active') === $s ? 'selected' : '' ?>>
                <?= ucfirst($s) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-check">
            <input type="checkbox" name="featured" id="featured" class="form-check-input" value="1"
                   <?= ((int)($product['featured'] ?? 0) === 1) ? 'checked' : '' ?>>
            <label for="featured" class="form-check-label">
              <i class="bi bi-star-fill text-warning"></i> Featured product
            </label>
          </div>
        </div>
      </div>

      <!-- Primary Image Card -->
      <div class="card mb-3 shadow-sm">
        <div class="card-body">
          <h6 class="card-title">Primary Image</h6>
          <div class="text-center mb-3">
            <img src="<?= e(product_image_url($product['image'] ?? null)) ?>"
                 id="primary_image_preview" class="img-fluid rounded border p-1" style="max-height:180px; object-fit:cover;"
                 alt="Product image preview">
          </div>
          <input type="file" name="image" class="form-control mb-1" accept="image/*">
          <small class="text-muted">JPG, PNG, GIF, WEBP &le; 4 MB.</small>
        </div>
      </div>

      <!-- Image Gallery Card -->
      <div class="card mb-3 shadow-sm">
        <div class="card-body">
          <h6 class="card-title mb-2">Product Gallery</h6>
          <p class="text-muted small">Upload multiple images. Used by variant image pickers.</p>
          <input type="file" name="gallery_images[]" class="form-control mb-3" multiple accept="image/*">

          <?php if (!empty($existingImages)): ?>
            <div class="row g-2">
              <?php foreach ($existingImages as $img): ?>
                <div class="col-4 text-center">
                  <div class="border rounded p-1 position-relative">
                    <img src="<?= e(product_image_url($img['filename'])) ?>" class="img-fluid rounded" style="height:60px; object-fit:cover;" alt="">
                    <div class="form-check mt-1">
                      <input type="radio" name="selected_primary_image" value="<?= e($img['filename']) ?>"
                             class="form-check-input" <?= ($product['image'] === $img['filename']) ? 'checked' : '' ?> title="Set as primary">
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Actions Card -->
      <div class="card shadow-sm">
        <div class="card-body">
          <button type="submit" class="btn btn-primary w-100 mb-2">
            <i class="bi bi-check2"></i> <?= $id ? 'Save changes' : 'Create product' ?>
          </button>
          <a href="<?= e(admin_url('products.php')) ?>" class="btn btn-light w-100 mb-2">Cancel</a>
          <?php if ($id && !empty($product['slug'])): ?>
            <a href="<?= e(url('product.php?slug=' . urlencode($product['slug']))) ?>" target="_blank" class="btn btn-outline-secondary w-100">
              <i class="bi bi-box-arrow-up-right"></i> View on store
            </a>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>
</form>

<script src="<?= e(url('assets/js/admin-variants.js')) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const initialOptions  = <?= json_encode($existingOptions, JSON_UNESCAPED_UNICODE) ?>;
  const initialVariants = <?= json_encode($existingVariants, JSON_UNESCAPED_UNICODE) ?>;
  const initialGallery  = <?= json_encode($existingImages, JSON_UNESCAPED_UNICODE) ?>;

  if (window.initProductVariantsBuilder) {
    window.initProductVariantsBuilder(initialOptions, initialVariants, initialGallery);
  }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
