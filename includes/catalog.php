<?php
/**
 * Catalog helpers: products, options, variants, pricing, and availability.
 */
require_once __DIR__ . '/../config/database.php';

/**
 * Fetch a single product by ID (any status).
 */
function product_get(int $id): ?array
{
    $stmt = db()->prepare("SELECT * FROM products WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Fetch a product by slug for storefront (active only).
 */
function product_get_by_slug(string $slug): ?array
{
    $stmt = db()->prepare("SELECT * FROM products WHERE slug = :slug AND status = 'active' LIMIT 1");
    $stmt->execute([':slug' => $slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Get all options with their values for a product.
 * Returns: [ ['id','name','display_type','values'=>[['id','value','swatch_hex']]] ] ordered by position.
 */
function product_options_get(int $productId): array
{
    $stmtOpt = db()->prepare(
        "SELECT id, name, display_type, position
         FROM product_options
         WHERE product_id = :pid
         ORDER BY position ASC, id ASC"
    );
    $stmtOpt->execute([':pid' => $productId]);
    $options = $stmtOpt->fetchAll();
    if (!$options) {
        return [];
    }

    $optIds = array_map('intval', array_column($options, 'id'));
    $placeholders = implode(',', array_fill(0, count($optIds), '?'));

    $stmtVal = db()->prepare(
        "SELECT id, option_id, value, swatch_hex, position
         FROM product_option_values
         WHERE option_id IN ($placeholders)
         ORDER BY position ASC, id ASC"
    );
    $stmtVal->execute($optIds);
    $allValues = $stmtVal->fetchAll();

    $valuesByOpt = [];
    foreach ($allValues as $val) {
        $valuesByOpt[$val['option_id']][] = [
            'id'         => (int)$val['id'],
            'value'      => $val['value'],
            'swatch_hex' => $val['swatch_hex'],
        ];
    }

    $result = [];
    foreach ($options as $opt) {
        $result[] = [
            'id'           => (int)$opt['id'],
            'name'         => $opt['name'],
            'display_type' => $opt['display_type'],
            'position'     => (int)$opt['position'],
            'values'       => $valuesByOpt[$opt['id']] ?? [],
        ];
    }

    return $result;
}

/**
 * Get all variants for a product.
 * Each variant includes:
 * - option_value_ids: array map [option_id => value_id]
 * - label: formatted string, e.g. "Size: M / Color: Red"
 */
function variants_get(int $productId, bool $onlyActive = true): array
{
    $sql = "SELECT * FROM product_variants WHERE product_id = :pid";
    if ($onlyActive) {
        $sql .= " AND status = 'active'";
    }
    $sql .= " ORDER BY position ASC, id ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute([':pid' => $productId]);
    $variants = $stmt->fetchAll();
    if (!$variants) {
        return [];
    }

    $variantIds = array_map('intval', array_column($variants, 'id'));
    $placeholders = implode(',', array_fill(0, count($variantIds), '?'));

    $valSql = "
        SELECT pvv.variant_id, pvv.option_id, pvv.option_value_id,
               po.name AS option_name, pov.value AS option_value
        FROM product_variant_values pvv
        JOIN product_options po ON po.id = pvv.option_id
        JOIN product_option_values pov ON pov.id = pvv.option_value_id
        WHERE pvv.variant_id IN ($placeholders)
        ORDER BY po.position ASC, po.id ASC
    ";
    $stmtVals = db()->prepare($valSql);
    $stmtVals->execute($variantIds);
    $rows = $stmtVals->fetchAll();

    $map = [];
    $labels = [];
    foreach ($rows as $r) {
        $vid  = (int)$r['variant_id'];
        $oid  = (int)$r['option_id'];
        $ovid = (int)$r['option_value_id'];
        $map[$vid][$oid] = $ovid;
        $labels[$vid][] = $r['option_name'] . ': ' . $r['option_value'];
    }

    foreach ($variants as &$v) {
        $vid = (int)$v['id'];
        $v['id'] = $vid;
        $v['product_id'] = (int)$v['product_id'];
        $v['stock'] = (int)$v['stock'];
        $v['option_value_ids'] = $map[$vid] ?? [];
        $v['label'] = !empty($labels[$vid]) ? implode(' / ', $labels[$vid]) : ($v['sku'] ?: 'Default');
    }
    unset($v);

    return $variants;
}

/**
 * Fetch a single variant by ID, including hydrated option_value_ids and label.
 */
function variant_get(int $variantId): ?array
{
    $stmt = db()->prepare("SELECT * FROM product_variants WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $variantId]);
    $v = $stmt->fetch();
    if (!$v) {
        return null;
    }

    $valSql = "
        SELECT pvv.option_id, pvv.option_value_id,
               po.name AS option_name, pov.value AS option_value
        FROM product_variant_values pvv
        JOIN product_options po ON po.id = pvv.option_id
        JOIN product_option_values pov ON pov.id = pvv.option_value_id
        WHERE pvv.variant_id = :vid
        ORDER BY po.position ASC, po.id ASC
    ";
    $stmtVals = db()->prepare($valSql);
    $stmtVals->execute([':vid' => $variantId]);
    $rows = $stmtVals->fetchAll();

    $map = [];
    $labels = [];
    foreach ($rows as $r) {
        $oid  = (int)$r['option_id'];
        $ovid = (int)$r['option_value_id'];
        $map[$oid] = $ovid;
        $labels[] = $r['option_name'] . ': ' . $r['option_value'];
    }

    $v['id'] = (int)$v['id'];
    $v['product_id'] = (int)$v['product_id'];
    $v['stock'] = (int)$v['stock'];
    $v['option_value_ids'] = $map;
    $v['label'] = !empty($labels) ? implode(' / ', $labels) : ($v['sku'] ?: 'Default');

    return $v;
}

/**
 * Effective price for a variant in context of its product.
 * Hierarchy: variant sale_price -> variant price -> product sale_price -> product price.
 */
function variant_effective_price(array $variant, array $product): float
{
    if (isset($variant['sale_price']) && $variant['sale_price'] !== null && (float)$variant['sale_price'] > 0) {
        return (float)$variant['sale_price'];
    }
    if (isset($variant['price']) && $variant['price'] !== null && (float)$variant['price'] > 0) {
        return (float)$variant['price'];
    }
    if (isset($product['sale_price']) && $product['sale_price'] !== null && (float)$product['sale_price'] > 0) {
        return (float)$product['sale_price'];
    }
    return (float)($product['price'] ?? 0.0);
}

/**
 * Available quantity for a variant, honoring track_inventory and allow_backorder.
 */
function variant_available_qty(array $variant, array $product): int
{
    if (isset($product['track_inventory']) && (int)$product['track_inventory'] === 0) {
        return 999999;
    }
    if (!empty($product['allow_backorder'])) {
        return 999999;
    }
    return max(0, (int)($variant['stock'] ?? 0));
}

/**
 * Available quantity for a product using v_product_stock.
 */
function product_available_qty(int $productId): int
{
    $p = product_get($productId);
    if (!$p) {
        return 0;
    }
    if (isset($p['track_inventory']) && (int)$p['track_inventory'] === 0) {
        return 999999;
    }
    if (!empty($p['allow_backorder'])) {
        return 999999;
    }

    try {
        $stmt = db()->prepare("SELECT total_stock FROM v_product_stock WHERE product_id = :pid LIMIT 1");
        $stmt->execute([':pid' => $productId]);
        $val = $stmt->fetchColumn();
        if ($val !== false && $val !== null) {
            return max(0, (int)$val);
        }
    } catch (Throwable $e) {
        // Fallback to product.stock if view query fails
    }

    return max(0, (int)($p['stock'] ?? 0));
}

/**
 * Compute the option hash for a combination of option value IDs.
 * Matches product_variants.option_hash: md5 of sorted option_value IDs.
 */
function variant_hash(array $optionValueIds): string
{
    $ids = array_values(array_map('intval', $optionValueIds));
    sort($ids, SORT_NUMERIC);
    return md5(implode('-', $ids));
}

/**
 * Get all gallery images for a product, sorted with primary first.
 */
function product_gallery_images(int $productId): array
{
    $stmt = db()->prepare(
        "SELECT * FROM product_images
         WHERE product_id = :pid
         ORDER BY is_primary DESC, position ASC, id ASC"
    );
    $stmt->execute([':pid' => $productId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Get the second image filename for product card hover transition.
 */
function product_second_image(int $productId): ?string
{
    $stmt = db()->prepare(
        "SELECT filename FROM product_images
         WHERE product_id = :pid AND is_primary = 0
         ORDER BY position ASC, id ASC LIMIT 1"
    );
    $stmt->execute([':pid' => $productId]);
    $res = $stmt->fetchColumn();
    return $res ?: null;
}

/**
 * Get color swatches for product cards (up to 5 + count).
 */
function product_color_swatches(int $productId): array
{
    $stmt = db()->prepare(
        "SELECT pov.value, pov.swatch_hex
         FROM product_option_values pov
         JOIN product_options po ON po.id = pov.option_id
         WHERE po.product_id = :pid
           AND (po.display_type = 'color' OR LOWER(po.name) IN ('color', 'colour'))
         GROUP BY pov.value, pov.swatch_hex
         ORDER BY MIN(pov.position) ASC, MIN(pov.id) ASC"
    );
    $stmt->execute([':pid' => $productId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Get active storefront banners for a given placement.
 */
function storefront_banners_get(string $placement = 'hero'): array
{
    $now = date('Y-m-d H:i:s');
    $stmt = db()->prepare(
        "SELECT * FROM storefront_banners
         WHERE placement = :plc
           AND status = 'active'
           AND (starts_at IS NULL OR starts_at <= :n1)
           AND (ends_at IS NULL OR ends_at >= :n2)
         ORDER BY position ASC, id ASC"
    );
    $stmt->execute([':plc' => $placement, ':n1' => $now, ':n2' => $now]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Get delivery estimate string from active shipping methods.
 */
function shipping_delivery_estimate(): ?string
{
    try {
        $stmt = db()->query(
            "SELECT MIN(min_days) AS min_d, MAX(max_days) AS max_d
             FROM shipping_methods
             WHERE status = 'active'"
        );
        $row = $stmt->fetch();
        if ($row && !empty($row['max_d'])) {
            $min = (int)($row['min_d'] ?: 1);
            $max = (int)$row['max_d'];
            return "{$min}–{$max} business days";
        }
    } catch (Throwable $e) {}
    return '2–4 business days';
}

/**
 * Get available option values across all active products for shop filters.
 */
function storefront_filter_options(): array
{
    try {
        $stmt = db()->query(
            "SELECT po.name AS option_name, po.display_type, pov.id AS value_id, pov.value, pov.swatch_hex
             FROM product_option_values pov
             JOIN product_options po ON po.id = pov.option_id
             JOIN products p ON p.id = po.product_id
             WHERE p.status = 'active'
             GROUP BY po.name, pov.value
             ORDER BY po.name ASC, pov.position ASC, pov.value ASC"
        );
        $rows = $stmt->fetchAll() ?: [];
        $grouped = [];
        foreach ($rows as $r) {
            $name = $r['option_name'];
            if (!isset($grouped[$name])) {
                $grouped[$name] = [
                    'name'         => $name,
                    'display_type' => $r['display_type'],
                    'values'       => [],
                ];
            }
            $grouped[$name]['values'][] = [
                'id'         => (int)$r['value_id'],
                'value'      => $r['value'],
                'swatch_hex' => $r['swatch_hex'],
            ];
        }
        return $grouped;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Attach second_image + swatches to a list of product rows (in place).
 * Executes exactly 2 batch queries for any number of products to eliminate N+1 overhead.
 */
function products_attach_card_data(array &$products): void
{
    if (!$products) return;
    $ids = array_values(array_unique(array_filter(array_map('intval', array_column($products, 'id')))));
    if (!$ids) return;
    $in  = implode(',', array_fill(0, count($ids), '?'));

    $second = [];
    try {
        $st = db()->prepare(
            "SELECT product_id, filename FROM product_images
             WHERE product_id IN ($in) AND is_primary = 0
             ORDER BY product_id, position, id"
        );
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) {
            if (!isset($second[$r['product_id']])) {
                $second[$r['product_id']] = $r['filename'];
            }
        }
    } catch (Throwable $e) {}

    $sw = [];
    try {
        $st = db()->prepare(
            "SELECT po.product_id, pov.value, pov.swatch_hex
             FROM product_option_values pov
             JOIN product_options po ON po.id = pov.option_id
             WHERE po.product_id IN ($in)
               AND (po.display_type IN ('color','swatch') OR LOWER(po.name) IN ('color','colour'))
             ORDER BY po.product_id, pov.position, pov.id"
        );
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) {
            $sw[$r['product_id']][] = ['value' => $r['value'], 'swatch_hex' => $r['swatch_hex']];
        }
    } catch (Throwable $e) {}

    foreach ($products as &$p) {
        $pid = (int)$p['id'];
        $p['second_image'] = $second[$pid] ?? ''; // '' (not null) so the card won't re-query
        $p['swatches']     = $sw[$pid] ?? [];
    }
    unset($p);
}

/**
 * Recalculate average rating and review count for a product.
 */
function product_recalculate_rating(int $productId): void
{
    try {
        $stmt = db()->prepare("
            SELECT COUNT(*) as cnt, AVG(rating) as avg_score 
            FROM product_reviews 
            WHERE product_id = ? AND status = 'approved'
        ");
        $stmt->execute([$productId]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        $cnt = (int)($res['cnt'] ?? 0);
        $avg = $cnt > 0 ? round((float)$res['avg_score'], 2) : 0.00;

        $upd = db()->prepare("UPDATE products SET rating_count = ?, rating_avg = ? WHERE id = ?");
        $upd->execute([$cnt, $avg, $productId]);
    } catch (Throwable $e) {}
}


