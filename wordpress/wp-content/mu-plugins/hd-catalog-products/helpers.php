<?php
/**
 * HD Catalog Products — helpers.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Build prices_json tiers from CSV-style prices (1/2/3 units).
 * Discount % vs unit × unit1 price (0 if not computable).
 *
 * @param array<int,float|int|string> $prices
 * @return array<int,array{unit:int,price:float,discount:float}>
 */
function hd_catalog_products_build_prices(array $prices): array
{
    $out = [];
    $unit1 = null;
    $i = 0;
    foreach ($prices as $raw) {
        $i++;
        $price = (float) str_replace(',', '.', (string) $raw);
        if ($i === 1) {
            $unit1 = $price;
        }
        $discount = 0.0;
        if ($unit1 !== null && $unit1 > 0 && $i > 1) {
            $full = $unit1 * $i;
            if ($full > 0 && $price < $full) {
                $discount = round((($full - $price) / $full) * 100, 1);
            }
        }
        $out[] = [
            'unit' => $i,
            'price' => $price,
            'discount' => $discount,
        ];
    }
    return $out;
}

/**
 * @param mixed $json
 * @return array<int,mixed>
 */
function hd_catalog_products_decode_json($json): array
{
    if (is_array($json)) {
        return $json;
    }
    if (!is_string($json) || $json === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<int,mixed> $ids
 */
function hd_catalog_products_encode_ids(array $ids): string
{
    $clean = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $clean[] = $id;
        }
    }
    $clean = array_values(array_unique($clean));
    return wp_json_encode($clean);
}

/**
 * Resolve effective product for forms/LP/project: self, else parent primary.
 *
 * @param object|array<string,mixed> $product
 * @return object|null
 */
function hd_catalog_products_resolve_primary($product)
{
    global $wpdb;
    $table = hd_catalog_products_table();
    $row = is_object($product) ? $product : (object) $product;

    $hasRouting = ((string) ($row->form_key ?? '') !== '')
        || ((int) ($row->boutique_page_id ?? 0) > 0)
        || ((string) ($row->project_id ?? '') !== '' && (string) ($row->project_id ?? '') === (string) ($row->espo_id ?? ''));

    // Prefer parent when this row looks boutique-only (no form) and parent is set.
    $needsParent = ((string) ($row->form_key ?? '') === '')
        && (
            ((int) ($row->primary_product_id ?? 0) > 0)
            || ((string) ($row->parent_espo_id ?? '') !== '')
        );

    if (!$needsParent) {
        return $row;
    }

    if ((int) ($row->primary_product_id ?? 0) > 0) {
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
            (int) $row->primary_product_id
        ));
        if ($parent) {
            return $parent;
        }
    }

    if ((string) ($row->parent_espo_id ?? '') !== '') {
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE espo_id = %s LIMIT 1",
            (string) $row->parent_espo_id
        ));
        if ($parent) {
            return $parent;
        }
    }

    return $row;
}

/**
 * @return object|null
 */
function hd_catalog_products_get_by_espo(string $espoId)
{
    global $wpdb;
    $espoId = sanitize_text_field($espoId);
    if ($espoId === '') {
        return null;
    }
    $table = hd_catalog_products_table();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE espo_id = %s LIMIT 1",
        $espoId
    ));
}

/**
 * Lookup catalog row by HD form_key.
 * Prefer project_id match (variants), else primary (no parent), else first row.
 *
 * @return object|null
 */
function hd_catalog_products_get_by_form_key(string $formKey, string $projectId = '')
{
    global $wpdb;
    $formKey = sanitize_key($formKey);
    if ($formKey === '') {
        return null;
    }
    $table = hd_catalog_products_table();
    $projectId = sanitize_text_field($projectId);
    if ($projectId !== '') {
        $byProject = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE form_key = %s AND project_id = %s ORDER BY id ASC LIMIT 1",
            $formKey,
            $projectId
        ));
        if ($byProject) {
            return $byProject;
        }
    }
    // Shared form_keys (YM14/YM15 variants): prefer primary, not first id (which may be a variant).
    $primary = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE form_key = %s AND (parent_espo_id IS NULL OR parent_espo_id = '') ORDER BY id ASC LIMIT 1",
        $formKey
    ));
    if ($primary) {
        return $primary;
    }
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE form_key = %s ORDER BY id ASC LIMIT 1",
        $formKey
    ));
}

/**
 * Lookup catalog row by SKU (exact, case-insensitive).
 *
 * @return object|null
 */
function hd_catalog_products_get_by_sku(string $sku)
{
    global $wpdb;
    $sku = strtoupper(sanitize_text_field($sku));
    if ($sku === '') {
        return null;
    }
    $table = hd_catalog_products_table();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE UPPER(sku) = %s ORDER BY id ASC LIMIT 1",
        $sku
    ));
}

/**
 * Lookup catalog row by Espo / catalog project_id.
 *
 * @return object|null
 */
function hd_catalog_products_get_by_project_id(string $projectId)
{
    global $wpdb;
    $projectId = sanitize_text_field($projectId);
    if ($projectId === '') {
        return null;
    }
    $table = hd_catalog_products_table();
    // Prefer primary when several rows share weird data; project_id is usually unique.
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE project_id = %s ORDER BY (parent_espo_id IS NULL OR parent_espo_id = '') DESC, id ASC LIMIT 1",
        $projectId
    ));
}

/**
 * Price tier for N units from prices_json (1/2/3…).
 * Falls back to closest lower tier, then first tier.
 *
 * @param mixed $pricesJson
 * @return array{unit:int,price:float,discount:float}|null
 */
function hd_catalog_products_tier_for_units($pricesJson, int $units): ?array
{
    $tiers = hd_catalog_products_decode_json($pricesJson);
    if (!$tiers || $units < 1) {
        return null;
    }
    $byUnit = [];
    foreach ($tiers as $t) {
        if (!is_array($t)) {
            continue;
        }
        $u = (int) ($t['unit'] ?? 0);
        if ($u < 1) {
            continue;
        }
        $byUnit[$u] = [
            'unit' => $u,
            'price' => (float) ($t['price'] ?? 0),
            'discount' => (float) ($t['discount'] ?? 0),
        ];
    }
    if (!$byUnit) {
        return null;
    }
    if (isset($byUnit[$units])) {
        return $byUnit[$units];
    }
    $best = null;
    foreach ($byUnit as $u => $row) {
        if ($u <= $units && ($best === null || $u > $best['unit'])) {
            $best = $row;
        }
    }
    if ($best !== null) {
        return $best;
    }
    ksort($byUnit);
    return reset($byUnit) ?: null;
}

/**
 * @return object|null
 */
function hd_catalog_products_get(int $id)
{
    global $wpdb;
    if ($id <= 0) {
        return null;
    }
    $table = hd_catalog_products_table();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
        $id
    ));
}

/**
 * Soft leads count for a form_key (read-only).
 */
function hd_catalog_products_leads_count(string $formKey, int $days = 0): int
{
    global $wpdb;
    $formKey = sanitize_key($formKey);
    if ($formKey === '' || !function_exists('hd_native_leads_admin_table')) {
        $leadsTable = $wpdb->prefix . 'hd_leads';
    } else {
        $leadsTable = hd_native_leads_admin_table();
    }

    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $leadsTable));
    if ($exists !== $leadsTable) {
        return 0;
    }

    if ($days > 0) {
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$leadsTable} WHERE form_key = %s AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $formKey,
            $days
        ));
    }

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$leadsTable} WHERE form_key = %s",
        $formKey
    ));
}

/**
 * Overview stats for admin dashboard.
 *
 * @return array{total:int,with_form:int,with_boutique:int,orphans:int,leads_7d:int}
 */
function hd_catalog_products_overview_stats(): array
{
    global $wpdb;
    $table = hd_catalog_products_table();

    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    $withForm = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE form_key <> ''");
    $withBoutique = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$table} WHERE boutique_page_id > 0 OR white_page_id > 0"
    );
    $orphans = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$table}
         WHERE form_key = '' AND boutique_page_id = 0 AND white_page_id = 0
           AND (lp_page_ids IS NULL OR lp_page_ids = '' OR lp_page_ids = '[]')"
    );

    $leads7d = 0;
    $leadsTable = $wpdb->prefix . 'hd_leads';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $leadsTable));
    if ($exists === $leadsTable) {
        $keys = $wpdb->get_col("SELECT DISTINCT form_key FROM {$table} WHERE form_key <> ''");
        if ($keys) {
            $placeholders = implode(',', array_fill(0, count($keys), '%s'));
            $sql = "SELECT COUNT(*) FROM {$leadsTable}
                    WHERE form_key IN ({$placeholders})
                      AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            $leads7d = (int) $wpdb->get_var($wpdb->prepare($sql, $keys));
        }
    }

    return [
        'total' => $total,
        'with_form' => $withForm,
        'with_boutique' => $withBoutique,
        'orphans' => $orphans,
        'leads_7d' => $leads7d,
    ];
}

/**
 * Format prices short for list UI.
 */
function hd_catalog_products_prices_label($pricesJson): string
{
    $tiers = hd_catalog_products_decode_json($pricesJson);
    if (!$tiers) {
        return '—';
    }
    $parts = [];
    foreach ($tiers as $t) {
        $parts[] = (string) ((int) ($t['price'] ?? $t['price'] ?? 0));
    }
    return implode(' / ', $parts);
}

/**
 * Sanitize product payload for insert/update.
 *
 * @param array<string,mixed> $raw
 * @return array<string,mixed>
 */
function hd_catalog_products_sanitize_row(array $raw): array
{
    $prices = $raw['prices_json'] ?? [];
    if (is_string($prices)) {
        $prices = hd_catalog_products_decode_json($prices);
    }
    if (!is_array($prices)) {
        $prices = [];
    }

    $lp = $raw['lp_page_ids'] ?? [];
    if (is_string($lp)) {
        // Accept comma-separated IDs from form.
        if (strpos($lp, '[') === false) {
            $lp = array_filter(array_map('intval', preg_split('/[\s,;]+/', $lp) ?: []));
        } else {
            $lp = hd_catalog_products_decode_json($lp);
        }
    }

    $merci = $raw['merci_page_ids'] ?? [];
    if (is_string($merci)) {
        if (strpos($merci, '[') === false) {
            $merci = array_filter(array_map('intval', preg_split('/[\s,;]+/', $merci) ?: []));
        } else {
            $merci = hd_catalog_products_decode_json($merci);
        }
    }

    $status = sanitize_key((string) ($raw['status'] ?? 'active'));
    if (!in_array($status, ['active', 'draft'], true)) {
        $status = 'active';
    }

    return [
        'espo_id' => sanitize_text_field((string) ($raw['espo_id'] ?? '')),
        'sku' => strtoupper(sanitize_text_field((string) ($raw['sku'] ?? ''))),
        'name' => sanitize_text_field((string) ($raw['name'] ?? '')),
        'prices_json' => wp_json_encode(array_values($prices)),
        'image_attachment_id' => absint($raw['image_attachment_id'] ?? 0),
        'boutique_page_id' => absint($raw['boutique_page_id'] ?? 0),
        'white_page_id' => absint($raw['white_page_id'] ?? 0),
        'form_key' => sanitize_key((string) ($raw['form_key'] ?? '')),
        'project_id' => sanitize_text_field((string) ($raw['project_id'] ?? '')),
        'lp_page_ids' => hd_catalog_products_encode_ids(is_array($lp) ? $lp : []),
        'merci_page_ids' => hd_catalog_products_encode_ids(is_array($merci) ? $merci : []),
        'parent_espo_id' => sanitize_text_field((string) ($raw['parent_espo_id'] ?? '')),
        'primary_product_id' => absint($raw['primary_product_id'] ?? 0),
        'status' => $status,
        'notes' => sanitize_textarea_field((string) ($raw['notes'] ?? '')),
    ];
}
