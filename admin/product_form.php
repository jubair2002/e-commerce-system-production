<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('products.create', 'products.edit');

$id        = (int)($_GET['id'] ?? 0);
$pageTitle = $id ? 'Edit product' : 'Add product';
$errors    = [];

$product = [
    'id' => 0, 'category_id' => null, 'name' => '', 'slug' => '', 'sku' => '',
    'product_type' => 'simple',
    'short_description' => '', 'description' => '',
    'price' => '0.00', 'sale_price' => '', 'stock' => '0',
    'image' => null, 'status' => 'active', 'featured' => 0, 'is_preorder' => 0,
    'track_inventory' => 1, 'allow_backorder' => 0, 'low_stock_threshold' => 5,
    'show_stock_count' => 1, 'weight_g' => '', 'brand' => '', 'tags' => '',
    'meta_title' => '', 'meta_description' => '', 'meta_keywords' => '', 'updated_at' => '',
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
    $allDbVariants    = variants_get($id, false); // include inactive for admin editing

    // Filter out orphan/obsolete variants whose option values or option count no longer match current product options
    $validOptionValueIds = [];
    $activeOptCount = count($existingOptions);
    foreach ($existingOptions as $opt) {
        foreach (($opt['values'] ?? []) as $val) {
            $validOptionValueIds[(int)$val['id']] = true;
        }
    }

    $existingVariants = [];
    foreach ($allDbVariants as $v) {
        $vOptValIds = array_values($v['option_value_ids'] ?? []);
        $matchesCurrentOptions = ($activeOptCount > 0 && count($vOptValIds) === $activeOptCount);
        if ($matchesCurrentOptions) {
            foreach ($vOptValIds as $ovid) {
                if (!isset($validOptionValueIds[(int)$ovid])) {
                    $matchesCurrentOptions = false;
                    break;
                }
            }
        }
        if ($matchesCurrentOptions || empty($existingOptions)) {
            $existingVariants[] = $v;
        }
    }

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
    $isPreorder        = isset($_POST['is_preorder']) ? 1 : 0;

    $trackInventory    = isset($_POST['track_inventory']) ? 1 : 0;
    $allowBackorder    = isset($_POST['allow_backorder']) ? 1 : 0;
    $lowStockThreshold = max(0, (int)($_POST['low_stock_threshold'] ?? 5));
    $showStockCount    = isset($_POST['show_stock_count']) ? 1 : 0;
    $weightG           = (isset($_POST['weight_g']) && $_POST['weight_g'] !== '' && is_numeric($_POST['weight_g'])) ? (int)$_POST['weight_g'] : null;
    $brand             = trim((string)($_POST['brand'] ?? ''));
    $tags              = trim((string)($_POST['tags'] ?? ''));
    $metaTitle         = trim((string)($_POST['meta_title'] ?? ''));
    $metaDesc          = trim((string)($_POST['meta_description'] ?? ''));
    $metaKeywords      = trim((string)($_POST['meta_keywords'] ?? ''));

    // Validation
    if ($name === '') {
        $errors[] = 'Product name is required.';
    }
    if (!is_numeric($price) || (float)$price < 0) {
        $errors[] = 'Price must be a non-negative number.';
    }
    if ($salePrice !== '' && (!is_numeric($salePrice) || (float)$salePrice < 0)) {
        $errors[] = 'Sale price must be less than the regular price.';
    }
    if ($salePrice !== '' && is_numeric($salePrice) && is_numeric($price) && (float)$salePrice >= (float)$price) {
        $errors[] = 'Sale price must be less than the regular price.';
    }

    // SKU uniqueness across products and variants
    if ($sku !== '') {
        $stmt = db()->prepare(
            'SELECT id FROM products WHERE sku = ? AND id <> ?
             UNION
             SELECT product_id FROM product_variants WHERE sku = ? AND product_id <> ?'
        );
        $stmt->execute([$sku, $id, $sku, $id]);
        if ($stmt->fetch()) {
            $errors[] = 'The product SKU is already in use by another product or variant.';
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
                    "SELECT id FROM products WHERE sku = ? AND id <> ?
                     UNION
                     SELECT product_id FROM product_variants WHERE sku = ? AND product_id <> ?"
                );
                $stmtCheckSku->execute([$vSku, $id, $vSku, $id]);
                if ($stmtCheckSku->fetch()) {
                    $errors[] = "The variant SKU \"{$vSku}\" is already used by another product or variant.";
                }
            }
        }
    }

    // -------------------------------------------------------------
    // Image Upload Pre-Validation via StorageManager
    // -------------------------------------------------------------
    $hasPrimaryUpload = !empty($_FILES['image']['name']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $primaryFileId    = $product['file_id'] ?? null;
    $primaryImage     = $product['image'] ?? null;

    if ($hasPrimaryUpload) {
        $uploadErr = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($uploadErr !== UPLOAD_ERR_OK) {
            $errors[] = 'Primary image upload failed: ' . upload_error_message($uploadErr);
        } else {
            $size = (int)$_FILES['image']['size'];
            if ($size > 10 * 1024 * 1024) {
                $errors[] = 'Primary image exceeds 10 MB limit.';
            }
        }
    }

    // Gallery multi-upload pre-validation
    if (!empty($_FILES['gallery_images']['name']) && is_array($_FILES['gallery_images']['name'])) {
        foreach ($_FILES['gallery_images']['name'] as $gk => $gName) {
            if (empty($gName)) continue;
            $gErr = $_FILES['gallery_images']['error'][$gk] ?? UPLOAD_ERR_NO_FILE;
            if ($gErr === UPLOAD_ERR_NO_FILE) continue;
            if ($gErr !== UPLOAD_ERR_OK) {
                $errors[] = "Gallery image '{$gName}' failed to upload: " . upload_error_message($gErr);
                continue;
            }
            $gSize = (int)$_FILES['gallery_images']['size'][$gk];
            if ($gSize > 10 * 1024 * 1024) {
                $errors[] = "Gallery image '{$gName}' exceeds 10 MB limit.";
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
            $primaryFileId = $product['file_id'] ?? null;
            $removePrimary = !empty($_POST['remove_primary_image']);
            $uploadedPrimaryFile = null;

            if ($removePrimary && !$hasPrimaryUpload) {
                $primaryImage = null;
                $primaryFileId = null;
            }

            // Handle primary image upload if file selected and validated via StorageManager
            if ($hasPrimaryUpload) {
                $stored = storage()->storeUploadedFile('product', $_FILES['image'], [
                    'owner_type' => 'product',
                    'owner_id'   => $id ?: null,
                    'visibility' => 'public',
                ]);
                $primaryImage  = $stored['storage_key'];
                $primaryFileId = $stored['id'];
            }

            // Validate category existence to avoid foreign key violations
            if ($categoryId > 0) {
                $chkCat = $pdo->prepare("SELECT id FROM categories WHERE id = ?");
                $chkCat->execute([$categoryId]);
                if (!$chkCat->fetch()) {
                    $categoryId = 0;
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
                ':fid'   => $primaryFileId,
                ':st'    => $status,
                ':feat'  => $featured,
                ':preorder' => $isPreorder,
                ':ti'    => $trackInventory,
                ':ab'    => $allowBackorder,
                ':lst'   => $lowStockThreshold,
                ':ssc'   => $showStockCount,
                ':wg'    => $weightG,
                ':brand' => $brand !== '' ? $brand : null,
                ':tags'  => $tags !== '' ? $tags : null,
                ':mt'    => $metaTitle !== '' ? $metaTitle : null,
                ':md'    => $metaDesc !== '' ? $metaDesc : null,
                ':mk'    => $metaKeywords !== '' ? $metaKeywords : null,
            ];

            // Ensure is_preorder column exists on the products table (self-healing for legacy databases)
            static $hasPreorder = null;
            if ($hasPreorder === null) {
                try {
                    $chk = $pdo->query("SHOW COLUMNS FROM products LIKE 'is_preorder'")->fetch();
                    if (!$chk) {
                        $pdo->exec("ALTER TABLE `products` ADD COLUMN `is_preorder` TINYINT(1) NOT NULL DEFAULT 0 AFTER `featured`");
                        $hasPreorder = true;
                    } else {
                        $hasPreorder = true;
                    }
                } catch (Throwable $e) {
                    try {
                        $chk2 = $pdo->query("SHOW COLUMNS FROM products LIKE 'is_preorder'")->fetch();
                        $hasPreorder = (bool)$chk2;
                    } catch (Throwable) {
                        $hasPreorder = false;
                    }
                }
            }

            if (!$hasPreorder) {
                unset($productData[':preorder']);
            }

            $preorderSet = $hasPreorder ? ", is_preorder = :preorder" : "";
            $preorderCol = $hasPreorder ? ", is_preorder" : "";
            $preorderVal = $hasPreorder ? ", :preorder" : "";

            $oldStock = 0;
            if ($id) {
                $stmtOldStock = $pdo->prepare("SELECT stock, product_type FROM products WHERE id = :id");
                $stmtOldStock->execute([':id' => $id]);
                $oldProdRow = $stmtOldStock->fetch();
                $oldStock = (int)($oldProdRow['stock'] ?? 0);

                $updateData = $productData;
                unset($updateData[':stock']);
                $updateData[':id'] = $id;

                $stmtUpdate = $pdo->prepare(
                    "UPDATE products SET
                        category_id = :cid, name = :name, slug = :slug, product_type = :ptype, sku = :sku,
                        short_description = :sd, description = :d, price = :price, sale_price = :sp,
                        image = :img, file_id = :fid, status = :st, featured = :feat{$preorderSet},
                        track_inventory = :ti, allow_backorder = :ab, low_stock_threshold = :lst,
                        show_stock_count = :ssc, weight_g = :wg, brand = :brand, tags = :tags,
                        meta_title = :mt, meta_description = :md, meta_keywords = :mk
                     WHERE id = :id"
                );
                $stmtUpdate->execute($updateData);
            } else {
                $insertData = $productData;
                $insertData[':stock'] = 0;
                $stmtInsert = $pdo->prepare(
                    "INSERT INTO products
                        (category_id, name, slug, product_type, sku, short_description, description,
                         price, sale_price, stock, image, file_id, status, featured{$preorderCol},
                         track_inventory, allow_backorder, low_stock_threshold, show_stock_count,
                         weight_g, brand, tags, meta_title, meta_description, meta_keywords)
                     VALUES
                        (:cid, :name, :slug, :ptype, :sku, :sd, :d,
                         :price, :sp, :stock, :img, :fid, :st, :feat{$preorderVal},
                         :ti, :ab, :lst, :ssc, :wg, :brand, :tags, :mt, :md, :mk)"
                );
                $stmtInsert->execute($insertData);
                $id = (int)$pdo->lastInsertId();
            }

            // Ensure primary image exists in product_images gallery table as well
            if ($primaryImage && $id) {
                $chkPrimary = $pdo->prepare("SELECT id FROM product_images WHERE product_id = :pid AND (filename = :fn OR file_id = :fid)");
                $chkPrimary->execute([':pid' => $id, ':fn' => $primaryImage, ':fid' => $primaryFileId]);
                if (!$chkPrimary->fetchColumn()) {
                    $pdo->prepare("UPDATE product_images SET is_primary = 0 WHERE product_id = :pid")->execute([':pid' => $id]);
                    $pdo->prepare(
                        "INSERT INTO product_images (product_id, filename, file_id, alt_text, position, is_primary)
                         VALUES (:pid, :fn, :fid, :alt, 0, 1)"
                    )->execute([
                        ':pid' => $id,
                        ':fn'  => $primaryImage,
                        ':fid' => $primaryFileId,
                        ':alt' => $name,
                    ]);
                }
            }

            // Simple product stock adjustment ledger entry
            if ($productType === 'simple') {
                $delta = $stock - $oldStock;
                if ($delta !== 0) {
                    $reason = ($oldStock === 0) ? 'restock' : 'manual_adjust';
                    stock_adjust($id, null, $delta, $reason, null, 'Product edit form stock update');
                }
                // If switching from variable -> simple, keep existing variants inactive
                $pdo->prepare("UPDATE product_variants SET status = 'inactive' WHERE product_id = :pid")->execute([':pid' => $id]);
            } elseif ($productType === 'variable') {
                // For variable product, parent product stock is 0; variants manage inventory
                $pdo->prepare("UPDATE products SET stock = 0 WHERE id = :id")->execute([':id' => $id]);
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
                        $hex = (!empty($val['swatch_hex']) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $val['swatch_hex'])) ? $val['swatch_hex'] : null;
                        if (!$hex && $optType === 'swatch' && function_exists('color_name_to_hex')) {
                            $hex = color_name_to_hex($valStr);
                        }
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
                        // Fallback: If combo values couldn't be mapped directly, but existing variant ID is provided, load its value links
                        if (!empty($v['id'])) {
                            $existingVarId = (int)$v['id'];
                            $stmtVv = $pdo->prepare("SELECT option_id, option_value_id FROM product_variant_values WHERE variant_id = ? ORDER BY option_id");
                            $stmtVv->execute([$existingVarId]);
                            foreach ($stmtVv->fetchAll() as $vvr) {
                                $valIds[] = (int)$vvr['option_value_id'];
                                $linkPairs[] = ['option_id' => (int)$vvr['option_id'], 'value_id' => (int)$vvr['option_value_id']];
                            }
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

                // Clean up or deactivate variants no longer in combination list
                if ($activeHashes) {
                    $hashPlaceholders = implode(',', array_fill(0, count($activeHashes), '?'));
                    $findParams = array_merge([$id], $activeHashes);
                    $stmtOrphans = $pdo->prepare(
                        "SELECT id FROM product_variants WHERE product_id = ? AND option_hash NOT IN ($hashPlaceholders)"
                    );
                    $stmtOrphans->execute($findParams);
                    $orphanIds = $stmtOrphans->fetchAll(PDO::FETCH_COLUMN);

                    if (!empty($orphanIds)) {
                        $stmtCheckOrder = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE variant_id = ?");
                        $stmtDelVarVal  = $pdo->prepare("DELETE FROM product_variant_values WHERE variant_id = ?");
                        $stmtDelVar     = $pdo->prepare("DELETE FROM product_variants WHERE id = ?");
                        $stmtInactVar   = $pdo->prepare("UPDATE product_variants SET status = 'inactive' WHERE id = ?");

                        foreach ($orphanIds as $orphanId) {
                            $orphanId = (int)$orphanId;
                            $stmtCheckOrder->execute([$orphanId]);
                            $orderCount = (int)$stmtCheckOrder->fetchColumn();

                            if ($orderCount === 0) {
                                $stmtDelVarVal->execute([$orphanId]);
                                $stmtDelVar->execute([$orphanId]);
                            } else {
                                $stmtInactVar->execute([$orphanId]);
                            }
                        }
                    }
                }
            }

            // ---------------------------------------------------------
            // Product Images Gallery Multi-Upload & Management via StorageManager
            // ---------------------------------------------------------
            // 1. Delete selected gallery images
            if (!empty($_POST['delete_gallery_images']) && is_array($_POST['delete_gallery_images'])) {
                $delStmt = $pdo->prepare("SELECT filename, file_id FROM product_images WHERE id = :img_id AND product_id = :pid");
                $delExec = $pdo->prepare("DELETE FROM product_images WHERE id = :img_id AND product_id = :pid");
                foreach ($_POST['delete_gallery_images'] as $delId) {
                    $delId = (int)$delId;
                    if ($delId <= 0) continue;
                    $delStmt->execute([':img_id' => $delId, ':pid' => $id]);
                    $delRow = $delStmt->fetch(PDO::FETCH_ASSOC);
                    if ($delRow) {
                        $delFn  = $delRow['filename'];
                        $delFid = $delRow['file_id'];
                        $delExec->execute([':img_id' => $delId, ':pid' => $id]);
                        if ($delFid) {
                            storage()->deleteFile((int)$delFid);
                        }
                        // If deleted image was primary, pick next available
                        if ($primaryImage === $delFn) {
                            $nextPrimeStmt = $pdo->prepare("SELECT filename, file_id FROM product_images WHERE product_id = :pid ORDER BY is_primary DESC, position ASC LIMIT 1");
                            $nextPrimeStmt->execute([':pid' => $id]);
                            $nextRow = $nextPrimeStmt->fetch(PDO::FETCH_ASSOC);
                            $newPrimary = $nextRow['filename'] ?? null;
                            $newPrimaryFid = $nextRow['file_id'] ?? null;
                            $primaryImage = $newPrimary;
                            $primaryFileId = $newPrimaryFid;
                            $pdo->prepare("UPDATE products SET image = :img, file_id = :fid WHERE id = :id")->execute([':img' => $newPrimary, ':fid' => $newPrimaryFid, ':id' => $id]);
                            if ($newPrimary) {
                                $pdo->prepare("UPDATE product_images SET is_primary = 1 WHERE product_id = :pid AND filename = :fn")->execute([':pid' => $id, ':fn' => $newPrimary]);
                            }
                        }
                        // Delete legacy file from disk if not referenced by other products
                        $refCountStmt = $pdo->prepare("SELECT COUNT(*) FROM product_images WHERE filename = :fn");
                        $refCountStmt->execute([':fn' => $delFn]);
                        if ((int)$refCountStmt->fetchColumn() === 0) {
                            $filePath = UPLOADS_PATH . '/products/' . $delFn;
                            if (is_file($filePath)) {
                                @unlink($filePath);
                            }
                        }
                    }
                }
            }

            // 2. Upload multiple gallery images via StorageManager
            if (!empty($_FILES['gallery_images']['name']) && is_array($_FILES['gallery_images']['name'])) {
                $stmtImgIns = $pdo->prepare(
                    "INSERT INTO product_images (product_id, filename, file_id, alt_text, position, is_primary)
                     VALUES (:pid, :fn, :fid, :alt, :pos, :ip)"
                );

                $curPosStmt = $pdo->prepare("SELECT COALESCE(MAX(position), 0) FROM product_images WHERE product_id = :pid");
                $curPosStmt->execute([':pid' => $id]);
                $nextImgPos = (int)$curPosStmt->fetchColumn() + 1;

                foreach ($_FILES['gallery_images']['name'] as $k => $fName) {
                    if (empty($fName) || ($_FILES['gallery_images']['error'][$k] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                        continue;
                    }
                    $fItem = [
                        'name'     => $fName,
                        'tmp_name' => $_FILES['gallery_images']['tmp_name'][$k],
                        'size'     => (int)$_FILES['gallery_images']['size'][$k],
                        'error'    => (int)$_FILES['gallery_images']['error'][$k],
                    ];
                    try {
                        $stored = storage()->storeUploadedFile('product', $fItem, [
                            'owner_type' => 'product',
                            'owner_id'   => $id,
                            'visibility' => 'public',
                        ]);
                        $isPrimaryFlag = 0;
                        if (empty($primaryImage)) {
                            $primaryImage  = $stored['storage_key'];
                            $primaryFileId = $stored['id'];
                            $isPrimaryFlag = 1;
                            $pdo->prepare("UPDATE products SET image = :img, file_id = :fid WHERE id = :id")->execute([
                                ':img' => $primaryImage,
                                ':fid' => $primaryFileId,
                                ':id'  => $id,
                            ]);
                        }
                        $stmtImgIns->execute([
                            ':pid' => $id,
                            ':fn'  => $stored['storage_key'],
                            ':fid' => $stored['id'],
                            ':alt' => $name,
                            ':pos' => $nextImgPos++,
                            ':ip'  => $isPrimaryFlag,
                        ]);
                    } catch (Throwable $uploadErr) {
                        error_log("[Product Gallery] Upload failed: " . $uploadErr->getMessage());
                    }
                }
            }

            // 3. Update primary image selection from gallery if chosen or handle removal
            if (!empty($_POST['selected_primary_image'])) {
                $selImg = trim($_POST['selected_primary_image']);
                $pdo->prepare("UPDATE product_images SET is_primary = IF(filename = :fn, 1, 0) WHERE product_id = :pid")->execute([':fn' => $selImg, ':pid' => $id]);
                $pdo->prepare("UPDATE products SET image = :img WHERE id = :id")->execute([':img' => $selImg, ':id' => $id]);
            } elseif ($removePrimary && !$hasPrimaryUpload) {
                $pdo->prepare("UPDATE product_images SET is_primary = 0 WHERE product_id = :pid")->execute([':pid' => $id]);
                $pdo->prepare("UPDATE products SET image = NULL, file_id = NULL WHERE id = :id")->execute([':id' => $id]);
            }

            // 4. Update gallery image positions / custom ordering
            if (!empty($_POST['gallery_order']) && is_array($_POST['gallery_order'])) {
                $posStmt = $pdo->prepare("UPDATE product_images SET position = :pos WHERE id = :id AND product_id = :pid");
                $gPos = 1;
                foreach ($_POST['gallery_order'] as $gId) {
                    $gId = (int)$gId;
                    if ($gId > 0) {
                        $posStmt->execute([':pos' => $gPos++, ':id' => $gId, ':pid' => $id]);
                    }
                }
            }

            $pdo->commit();

            if (class_exists('FileCache')) {
                FileCache::deletePattern('products:*');
                FileCache::deletePattern('home:*');
                FileCache::deletePattern('shop:*');
            }

            log_activity('product.save', "Saved product {$name} (type: {$productType}, variants: " . count($postedVariants) . ")");
            flash('success', $id ? 'Product saved successfully.' : 'Product created successfully.');
            admin_redirect('products.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Failed to save product: ' . $e->getMessage();
        }
    }

    $product = array_merge($product, [
        'name' => $name, 'slug' => $slug, 'sku' => $sku, 'product_type' => $productType,
        'category_id' => $categoryId ?: null,
        'short_description' => $shortDescription, 'description' => $description,
        'price' => $price, 'sale_price' => $salePrice, 'stock' => $stock,
        'status' => $status, 'featured' => $featured, 'is_preorder' => $isPreorder,
        'track_inventory' => $trackInventory, 'allow_backorder' => $allowBackorder,
        'low_stock_threshold' => $lowStockThreshold, 'show_stock_count' => $showStockCount,
        'weight_g' => $weightG, 'brand' => $brand, 'tags' => $tags,
        'meta_title' => $metaTitle, 'meta_description' => $metaDesc,
        'meta_keywords' => $metaKeywords,
    ]);

    if (!empty($postedOptions)) {
        $existingOptions = $postedOptions;
    }
    if (!empty($postedVariants)) {
        $existingVariants = $postedVariants;
    }
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
                  <th style="width:40px;" class="text-center">Action</th>
                </tr>
              </thead>
              <tbody id="variants_tbody">
                <tr><td colspan="10" class="text-center text-muted py-3">No variants generated yet.</td></tr>
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
          <div class="mb-3">
            <label class="form-label">Meta description</label>
            <textarea name="meta_description" class="form-control" rows="2" maxlength="255"><?= e($product['meta_description'] ?? '') ?></textarea>
          </div>
          <div>
            <label class="form-label">Meta keywords <small class="text-muted">(comma-separated)</small></label>
            <input type="text" name="meta_keywords" class="form-control" maxlength="255"
                   value="<?= e($product['meta_keywords'] ?? '') ?>" placeholder="e.g. fashion, clothing, summer, cotton">
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
          <div class="form-check mt-2">
            <input type="checkbox" name="is_preorder" id="is_preorder" class="form-check-input" value="1"
                   <?= ((int)($product['is_preorder'] ?? 0) === 1) ? 'checked' : '' ?>>
            <label for="is_preorder" class="form-check-label">
              <i class="bi bi-clock-history text-primary"></i> Pre-Order Product
            </label>
          </div>
        </div>
      </div>

      <!-- Primary Image Card -->
      <div class="card mb-3 shadow-sm">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="card-title mb-0">Primary Image</h6>
            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 <?= empty($product['image']) ? 'd-none' : '' ?>" id="remove_primary_image_btn" title="Remove primary image">
              <i class="bi bi-trash"></i> Remove
            </button>
          </div>
          <div class="text-center mb-3">
            <img src="<?= e(product_image_url($product['image'] ?? null)) ?>"
                 id="primary_image_preview" class="img-fluid rounded border p-1" style="max-height:180px; object-fit:cover;"
                 alt="Product image preview"
                 data-placeholder="<?= e(product_image_url(null)) ?>"
                 data-has-image="<?= !empty($product['image']) ? '1' : '0' ?>">
          </div>
          <input type="file" name="image" id="primary_image_input" class="form-control mb-1" accept="image/*">
          <input type="hidden" name="remove_primary_image" id="remove_primary_image" value="0">
          <small class="text-muted">JPG, PNG, GIF, WEBP &le; 4 MB.</small>
        </div>
      </div>

      <!-- Image Gallery Card -->
      <div class="card mb-3 shadow-sm">
        <div class="card-body">
          <h6 class="card-title mb-2">Product Gallery</h6>
          <p class="text-muted small mb-2">Upload multiple images. Hold Ctrl/Cmd to select multiple files.</p>
          <input type="file" name="gallery_images[]" class="form-control mb-3" multiple accept="image/*">

          <?php if (!empty($existingImages)): ?>
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="small fw-bold text-title">Gallery Images (<span id="gallery_count_num"><?= count($existingImages) ?></span>)</span>
              <span class="small text-muted" style="font-size:11px;">Drag or arrows to order &middot; Radio = Primary</span>
            </div>

            <div id="gallery_images_grid" class="row g-2">
              <?php foreach ($existingImages as $gIdx => $img): ?>
                <div class="col-4 text-center gallery-col" id="gallery_col_<?= (int)$img['id'] ?>" data-id="<?= (int)$img['id'] ?>">
                  <div class="gallery-card border rounded-3 p-1 position-relative bg-white shadow-sm transition-all h-100 d-flex flex-column justify-content-between"
                       id="gallery_item_<?= (int)$img['id'] ?>" draggable="true">

                    <!-- Hidden Order Input -->
                    <input type="hidden" name="gallery_order[]" value="<?= (int)$img['id'] ?>" class="gallery-order-val">

                    <!-- Hidden Delete Input -->
                    <input type="checkbox" name="delete_gallery_images[]" id="del_img_<?= (int)$img['id'] ?>" value="<?= (int)$img['id'] ?>" class="d-none">

                    <!-- Top Order Badge & Drag Handle -->
                    <div class="d-flex align-items-center justify-content-between px-1 mb-1" style="font-size: 11px;">
                      <span class="badge bg-light text-dark border gallery-pos-badge" style="font-size: 10px;">#<?= $gIdx + 1 ?></span>
                      <span class="gallery-drag-handle text-muted" style="cursor: grab;" title="Drag to reorder"><i class="bi bi-grip-vertical"></i></span>
                    </div>

                    <!-- Image Thumbnail -->
                    <div class="position-relative overflow-hidden rounded mb-1" style="height: 70px;">
                      <img src="<?= e(product_image_url($img['filename'])) ?>" class="w-100 h-100 rounded" style="object-fit:cover;" alt="">
                      <div id="del_badge_<?= (int)$img['id'] ?>" class="badge bg-danger position-absolute top-50 start-50 translate-middle d-none shadow-sm" style="font-size: 10px; z-index: 5;">
                        <i class="bi bi-trash"></i> To Delete
                      </div>
                    </div>

                    <!-- Bottom Controls: Primary Radio + Reorder Arrows + Delete Toggle -->
                    <div class="d-flex align-items-center justify-content-between px-1 pt-1 border-top mt-auto">
                      <!-- Primary Radio -->
                      <div class="form-check mb-0" title="Set as primary product image">
                        <input type="radio" name="selected_primary_image" value="<?= e($img['filename']) ?>"
                               class="form-check-input" <?= ($product['image'] === $img['filename'] || ((int)($img['is_primary'] ?? 0) === 1)) ? 'checked' : '' ?> style="cursor: pointer;">
                      </div>

                      <!-- Reorder Buttons -->
                      <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-light btn-sm p-0 px-1 border-0" title="Move earlier" onclick="moveGalleryItem(<?= (int)$img['id'] ?>, -1)">
                          <i class="bi bi-chevron-left" style="font-size: 11px;"></i>
                        </button>
                        <button type="button" class="btn btn-light btn-sm p-0 px-1 border-0" title="Move later" onclick="moveGalleryItem(<?= (int)$img['id'] ?>, 1)">
                          <i class="bi bi-chevron-right" style="font-size: 11px;"></i>
                        </button>
                      </div>

                      <!-- Delete Action Toggle -->
                      <button type="button" class="btn btn-outline-danger btn-sm p-0 px-1 border-0" id="del_btn_<?= (int)$img['id'] ?>" onclick="toggleGalleryDelete(<?= (int)$img['id'] ?>)" title="Click to mark image for deletion">
                        <i class="bi bi-trash fs-6"></i>
                      </button>
                    </div>

                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <!-- JavaScript for Drag-and-Drop, Arrow Reordering & Deletion -->
            <script>
            (function () {
              const grid = document.getElementById('gallery_images_grid');
              if (!grid) return;

              function refreshPositions() {
                const cols = grid.querySelectorAll('.gallery-col');
                cols.forEach((col, idx) => {
                  const badge = col.querySelector('.gallery-pos-badge');
                  if (badge) badge.textContent = '#' + (idx + 1);
                });
              }

              window.moveGalleryItem = function (id, dir) {
                const col = document.getElementById('gallery_col_' + id);
                if (!col) return;
                if (dir === -1 && col.previousElementSibling) {
                  grid.insertBefore(col, col.previousElementSibling);
                  refreshPositions();
                  saveGalleryOrderAjax();
                } else if (dir === 1 && col.nextElementSibling) {
                  grid.insertBefore(col.nextElementSibling, col);
                  refreshPositions();
                  saveGalleryOrderAjax();
                }
              };

              window.toggleGalleryDelete = function (id) {
                const cb = document.getElementById('del_img_' + id);
                const item = document.getElementById('gallery_item_' + id);
                const badge = document.getElementById('del_badge_' + id);
                const btn = document.getElementById('del_btn_' + id);
                if (!cb || !item) return;

                cb.checked = !cb.checked;
                if (cb.checked) {
                  item.style.opacity = '0.45';
                  item.style.borderColor = '#dc3545';
                  item.style.backgroundColor = '#fff5f5';
                  if (badge) badge.classList.remove('d-none');
                  if (btn) {
                    btn.classList.remove('btn-outline-danger');
                    btn.classList.add('btn-danger', 'text-white');
                  }
                } else {
                  item.style.opacity = '1';
                  item.style.borderColor = '';
                  item.style.backgroundColor = '';
                  if (badge) badge.classList.add('d-none');
                  if (btn) {
                    btn.classList.remove('btn-danger', 'text-white');
                    btn.classList.add('btn-outline-danger');
                  }
                }
              };

              // Drag and drop implementation
              let draggedCol = null;
              grid.querySelectorAll('.gallery-card').forEach(card => {
                card.addEventListener('dragstart', (e) => {
                  draggedCol = card.closest('.gallery-col');
                  e.dataTransfer.effectAllowed = 'move';
                  card.classList.add('opacity-50');
                });

                card.addEventListener('dragend', () => {
                  card.classList.remove('opacity-50');
                  draggedCol = null;
                  refreshPositions();
                  saveGalleryOrderAjax();
                });

                card.addEventListener('dragover', (e) => {
                  e.preventDefault();
                  e.dataTransfer.dropEffect = 'move';
                });

                card.addEventListener('drop', (e) => {
                  e.preventDefault();
                  const targetCol = card.closest('.gallery-col');
                  if (draggedCol && targetCol && draggedCol !== targetCol) {
                    const allCols = Array.from(grid.querySelectorAll('.gallery-col'));
                    const draggedIdx = allCols.indexOf(draggedCol);
                    const targetIdx = allCols.indexOf(targetCol);
                    if (draggedIdx < targetIdx) {
                      grid.insertBefore(draggedCol, targetCol.nextSibling);
                    } else {
                      grid.insertBefore(draggedCol, targetCol);
                    }
                    refreshPositions();
                    saveGalleryOrderAjax();
                  }
                });
              });

              // Save order via AJAX in background when changed
              function saveGalleryOrderAjax() {
                const prodId = <?= (int)$id ?>;
                if (!prodId) return;
                const order = Array.from(grid.querySelectorAll('.gallery-order-val')).map(inp => parseInt(inp.value, 10));
                const fd = new FormData();
                fd.append('action', 'reorder');
                fd.append('product_id', prodId);
                order.forEach(id => fd.append('order[]', id));
                fetch('ajax/gallery_action.php', { method: 'POST', body: fd })
                  .then(r => r.json())
                  .catch(() => {});
              }
            })();
            </script>
            <small class="text-muted d-block mt-2" style="font-size:11px;">
              <i class="bi bi-arrows-move me-1"></i> Drag cards or click &larr; &rarr; to reorder gallery. Click trash to mark for deletion.
            </small>
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

<script src="<?= e(url('assets/js/admin-variants.js')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/js/admin-variants.js') ?: time() ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const initialOptions  = <?= json_encode($existingOptions, JSON_UNESCAPED_UNICODE) ?>;
  const initialVariants = <?= json_encode($existingVariants, JSON_UNESCAPED_UNICODE) ?>;
  const initialGallery  = <?= json_encode($existingImages, JSON_UNESCAPED_UNICODE) ?>;

  if (window.initProductVariantsBuilder) {
    window.initProductVariantsBuilder(initialOptions, initialVariants, initialGallery);
  }

  // Primary Image Preview and Remove Handler
  const primaryInput = document.getElementById('primary_image_input');
  const previewImg   = document.getElementById('primary_image_preview');
  const removeBtn    = document.getElementById('remove_primary_image_btn');
  const removeHidden = document.getElementById('remove_primary_image');

  if (primaryInput && previewImg) {
    primaryInput.addEventListener('change', function () {
      const file = this.files && this.files[0];
      if (file) {
        if (!file.type.startsWith('image/')) {
          alert('Please select a valid image file (JPG, PNG, GIF, WEBP).');
          this.value = '';
          return;
        }
        const reader = new FileReader();
        reader.onload = function (e) {
          previewImg.src = e.target.result;
          if (removeHidden) removeHidden.value = '0';
          if (removeBtn) removeBtn.classList.remove('d-none');
        };
        reader.readAsDataURL(file);
      }
    });
  }

  if (removeBtn && previewImg) {
    removeBtn.addEventListener('click', function () {
      if (primaryInput) primaryInput.value = '';
      if (removeHidden) removeHidden.value = '1';
      const placeholder = previewImg.dataset.placeholder || '';
      if (placeholder) {
        previewImg.src = placeholder;
      }
      removeBtn.classList.add('d-none');
      document.querySelectorAll('input[name="selected_primary_image"]').forEach(r => { r.checked = false; });
    });
  }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
