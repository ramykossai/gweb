<?php
/**
 * HD Catalog Products — Espo seed (idempotent on espo_id).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Canonical Espo product list (strict IDs).
 *
 * @return array<int,array{espo_id:string,sku:string,name:string,prices:array<int,int|float>,parent_espo_id?:string,notes?:string}>
 */
function hd_catalog_products_seed_definitions(): array
{
    return [
        ['espo_id' => '69f36e42dd6718ed5', 'sku' => 'YM39', 'name' => 'YM39 MinciMax', 'prices' => [109, 168, 198]],
        ['espo_id' => '69ef8beb947a0d7e0', 'sku' => 'YM38', 'name' => 'YM38 GROWY', 'prices' => [69, 109, 129]],
        ['espo_id' => '69baa8e14e8cb2ade', 'sku' => 'YM37', 'name' => 'YM37 PACK VIRILIZ', 'prices' => [139]],
        ['espo_id' => '69a17c9b8e09824d0', 'sku' => 'YM36', 'name' => 'YM36 PACK WARRIOR', 'prices' => [97]],
        ['espo_id' => '69998959c41629065', 'sku' => 'YM35', 'name' => 'YM35 DIABEXA', 'prices' => [79, 129, 149]],
        ['espo_id' => '6943d6e2204db1790', 'sku' => 'YM29-2', 'name' => 'YM29-2 THE SHOT', 'prices' => [76, 119, 139], 'notes' => 'Variante THE SHOT'],
        ['espo_id' => '6942d045c6b5e9d10', 'sku' => 'YM34', 'name' => 'YM34 Brain Up', 'prices' => [69, 109, 129]],
        ['espo_id' => '6939aab5b6352a43f', 'sku' => 'YM33', 'name' => 'YM33 MAGIC WHITE', 'prices' => [49]],
        ['espo_id' => '692d7e96b74798d51', 'sku' => 'YM32', 'name' => 'YM32 HEMORRELIEF', 'prices' => [69, 109, 129]],
        ['espo_id' => '692c3543e9f261be7', 'sku' => 'YM31', 'name' => 'YM31 ATOPI CARE', 'prices' => [69, 109, 129]],
        ['espo_id' => '692afefca603a360c', 'sku' => 'YM30', 'name' => 'YM30 VISIONET', 'prices' => [69, 109, 129]],
        ['espo_id' => '6915993d018876220', 'sku' => 'YM28-2', 'name' => 'YM28 "2" Blocpoux', 'prices' => [15], 'parent_espo_id' => '68e502b025e623a63', 'notes' => 'Variante boutique → YM28 Stopoux'],
        ['espo_id' => '69088c3ac04014bbd', 'sku' => 'YM27-G', 'name' => 'YM27 Acoufin goutte', 'prices' => [76], 'parent_espo_id' => '68db9f439ab64654c', 'notes' => 'Variante → YM27 Acoufin'],
        ['espo_id' => '69025982ab7e0f1ff', 'sku' => 'YM29', 'name' => 'YM29 THE SHOT', 'prices' => [76]],
        ['espo_id' => '68f0f9aa2b4101b36', 'sku' => 'YM04', 'name' => 'YM04 Full Relax', 'prices' => [64, 99, 115]],
        ['espo_id' => '68efd25d4513b81eb', 'sku' => 'YM03', 'name' => 'YM03 Deep Sleep', 'prices' => [66, 99, 115]],
        ['espo_id' => '68e502b025e623a63', 'sku' => 'YM28', 'name' => 'YM28 Stopoux', 'prices' => [59, 99, 149]],
        ['espo_id' => '68db9f439ab64654c', 'sku' => 'YM27', 'name' => 'YM27 Acoufin', 'prices' => [79, 129, 149]],
        ['espo_id' => '68b8768fa4e03e1ae', 'sku' => 'YM26', 'name' => 'YM26 Pump Me', 'prices' => [76, 115]],
        ['espo_id' => '68b1b9f8600b59a89', 'sku' => 'YM25', 'name' => 'YM25 Respira', 'prices' => [69, 99, 129]],
        ['espo_id' => '688c92cf8398a57b7', 'sku' => 'YM24', 'name' => 'YM24 Pure Intima', 'prices' => [56, 79, 99]],
        ['espo_id' => '683dd9f8e31047547', 'sku' => 'YM23', 'name' => 'YM23 Virgin', 'prices' => [84, 119, 148]],
        ['espo_id' => '6839e75ccf7e7c19b', 'sku' => 'YM22', 'name' => 'YM22 Mega Mass', 'prices' => [89, 129, 147]],
        ['espo_id' => '67c077f9df5351265', 'sku' => 'YM14-C', 'name' => 'YM14 Collagene CAPSULES', 'prices' => [56], 'parent_espo_id' => '6788ec725820aadd3', 'notes' => 'Variante boutique → YM14 Collagene'],
        ['espo_id' => '67c077bbea6400ea3', 'sku' => 'YM14-S', 'name' => 'YM14 Collagene SERUM', 'prices' => [56], 'parent_espo_id' => '6788ec725820aadd3', 'notes' => 'Variante boutique → YM14 Collagene'],
        ['espo_id' => '67a0dedfb01706ba4', 'sku' => 'YM15-G', 'name' => 'YM15 Fire Bowl Gel', 'prices' => [69, 127], 'parent_espo_id' => '6788eb070ad84a92a', 'notes' => 'Variante boutique → YM15 Fire Bowl'],
        ['espo_id' => '67a0dec65233e2e5f', 'sku' => 'YM15-C', 'name' => 'YM15 Fire Bowl Capsules', 'prices' => [69, 127], 'parent_espo_id' => '6788eb070ad84a92a', 'notes' => 'Variante boutique → YM15 Fire Bowl'],
        ['espo_id' => '679cbd19086e7fe95', 'sku' => 'YM09', 'name' => 'YM09 Hair Revive', 'prices' => [89, 135, 170]],
        ['espo_id' => '6794c074dcbf8e186', 'sku' => 'YM10', 'name' => 'YM10 El Toro', 'prices' => [79, 119, 139]],
        ['espo_id' => '6788fe24f402ceeea', 'sku' => 'YM13', 'name' => 'YM13 kardiax', 'prices' => [69, 99, 120]],
        ['espo_id' => '6788fd1420a471aeb', 'sku' => 'YM20', 'name' => 'YM20 Gel Cellulite', 'prices' => [69]],
        ['espo_id' => '6788fbdf45d3c1adc', 'sku' => 'YM19', 'name' => 'YM19 Gel Minceur', 'prices' => [79]],
        ['espo_id' => '6788f6c995c5efd48', 'sku' => 'YM21', 'name' => 'YM21 Push Me', 'prices' => [86, 119, 149]],
        ['espo_id' => '6788f170446c79618', 'sku' => 'YM18', 'name' => 'YM18 Mycose', 'prices' => [69, 99, 129]],
        ['espo_id' => '6788ee18486669e19', 'sku' => 'YM17', 'name' => 'YM17 Varistop', 'prices' => [69, 99, 129]],
        ['espo_id' => '6788ed71c64084775', 'sku' => 'YM16', 'name' => 'YM16 24 Carats', 'prices' => [69, 92, 120]],
        ['espo_id' => '6788eb070ad84a92a', 'sku' => 'YM15', 'name' => 'YM15 Fire Bowl', 'prices' => [127, 179, 217]],
        ['espo_id' => '6788ec725820aadd3', 'sku' => 'YM14', 'name' => 'YM14 Collagene', 'prices' => [79, 119, 149]],
        ['espo_id' => '6788e923016b676ff', 'sku' => 'YM11', 'name' => 'YM11 Night secret', 'prices' => [79, 119, 137]],
        ['espo_id' => '6788e52395c10bc46', 'sku' => 'YM07', 'name' => 'YM07 Glory Gel', 'prices' => [79, 117, 137], 'parent_espo_id' => '6788d9c961fdebcc6', 'notes' => 'Doublon name / alias → Glory Gel principal'],
        ['espo_id' => '6788e33ce7bab0acd', 'sku' => 'YM08', 'name' => 'YM08 Maca Boost Women', 'prices' => [89, 119, 147]],
        ['espo_id' => '6788de2054c8ac7ee', 'sku' => 'YM06', 'name' => 'YM06 Prosta Pro', 'prices' => [86, 119, 146]],
        ['espo_id' => '6788dbd56710ce96e', 'sku' => 'YM05', 'name' => 'YM05 burn fast', 'prices' => [120, 179, 209]],
        ['espo_id' => '6788d9c961fdebcc6', 'sku' => 'YM07', 'name' => 'YM07 Glory Gel', 'prices' => [79, 117, 137], 'notes' => 'Principal (form GLORY)'],
        ['espo_id' => '6788d509d0a719d2f', 'sku' => 'YM02', 'name' => 'YM02 clean colon', 'prices' => [69, 99, 115]],
        ['espo_id' => '6788d3529d0999868', 'sku' => 'YM01', 'name' => 'YM01 Maca Stimul Pro', 'prices' => [79, 119, 139]],
    ];
}

function hd_catalog_products_maybe_seed(): void
{
    if (get_option(HD_CATALOG_PRODUCTS_OPTION_SEEDED) === '1') {
        global $wpdb;
        $table = hd_catalog_products_table();
        $have = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $want = count(hd_catalog_products_seed_definitions());
        if ($have >= $want) {
            return;
        }
        // Missing rows only (e.g. after partial delete) — insert + soft autolink.
        hd_catalog_products_seed_upsert(false, true);
        return;
    }

    hd_catalog_products_seed_upsert(true, true);
    update_option(HD_CATALOG_PRODUCTS_OPTION_SEEDED, '1', false);
}

/**
 * Insert missing seed rows; optionally refresh prices/name/sku/notes/parent on existing.
 *
 * @param bool $refreshExisting When true, update core seed fields on existing rows.
 * @param bool $runAutolink When true, soft-link forms/pages/images after upsert.
 */
function hd_catalog_products_seed_upsert(bool $refreshExisting = false, bool $runAutolink = true): int
{
    global $wpdb;
    $table = hd_catalog_products_table();
    $now = current_time('mysql');
    $count = 0;
    $inserted = 0;

    foreach (hd_catalog_products_seed_definitions() as $def) {
        $espoId = (string) $def['espo_id'];
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$table} WHERE espo_id = %s LIMIT 1",
            $espoId
        ));

        $pricesJson = wp_json_encode(hd_catalog_products_build_prices($def['prices']));
        $row = [
            'espo_id' => $espoId,
            'sku' => (string) $def['sku'],
            'name' => (string) $def['name'],
            'prices_json' => $pricesJson,
            'project_id' => $espoId,
            'parent_espo_id' => (string) ($def['parent_espo_id'] ?? ''),
            'notes' => (string) ($def['notes'] ?? ''),
            'status' => 'active',
            'updated_at' => $now,
        ];

        if ($existing) {
            if ($refreshExisting) {
                $wpdb->update($table, $row, ['id' => (int) $existing->id]);
            }
            $count++;
            continue;
        }

        $row['image_attachment_id'] = 0;
        $row['boutique_page_id'] = 0;
        $row['white_page_id'] = 0;
        $row['form_key'] = '';
        $row['lp_page_ids'] = '[]';
        $row['merci_page_ids'] = '[]';
        $row['primary_product_id'] = 0;
        $row['created_at'] = $now;
        $wpdb->insert($table, $row);
        $count++;
        $inserted++;
    }

    hd_catalog_products_resolve_primary_ids();
    if ($runAutolink || $inserted > 0) {
        if (function_exists('hd_catalog_products_autolink_all')) {
            hd_catalog_products_autolink_all();
        }
    }

    return $count;
}

/**
 * Fill primary_product_id from parent_espo_id.
 */
function hd_catalog_products_resolve_primary_ids(): void
{
    global $wpdb;
    $table = hd_catalog_products_table();
    $rows = $wpdb->get_results(
        "SELECT id, parent_espo_id FROM {$table} WHERE parent_espo_id <> '' AND primary_product_id = 0"
    );
    if (!$rows) {
        return;
    }
    foreach ($rows as $row) {
        $parent = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE espo_id = %s LIMIT 1",
            (string) $row->parent_espo_id
        ));
        if ($parent) {
            $wpdb->update(
                $table,
                ['primary_product_id' => (int) $parent, 'updated_at' => current_time('mysql')],
                ['id' => (int) $row->id]
            );
        }
    }
}
