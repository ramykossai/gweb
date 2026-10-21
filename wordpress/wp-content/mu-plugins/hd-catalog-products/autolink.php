<?php
/**
 * HD Catalog Products — soft auto-link (best effort, non-blocking).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * White-page slug hints per espo_id / sku family.
 *
 * @return array<string,string> espo_id => page slug
 */
function hd_catalog_products_white_slug_map(): array
{
    return [
        '6788d3529d0999868' => 'produit-maca-stimul-pro',
        '6788d509d0a719d2f' => 'produit-clean-colon',
        '68efd25d4513b81eb' => 'produit-deep-sleep',
        '68f0f9aa2b4101b36' => 'produit-full-relax',
        '6788dbd56710ce96e' => 'produit-burn-fast',
        '6788de2054c8ac7ee' => 'produit-prosta-pro',
        '6788d9c961fdebcc6' => 'produit-glory',
        '6788e52395c10bc46' => 'produit-glory',
        '6788e33ce7bab0acd' => 'produit-maca-boost-women',
        '679cbd19086e7fe95' => 'produit-hair',
        '6794c074dcbf8e186' => 'produit-el-toro',
        '6788e923016b676ff' => 'produit-night-secret',
        '6788fe24f402ceeea' => 'produit-kardiax',
        '6788ec725820aadd3' => 'produit-collagene',
        '67c077f9df5351265' => 'produit-collagene-capsules',
        '67c077bbea6400ea3' => 'produit-collagene-serum',
        '6788eb070ad84a92a' => 'produit-fire-bowl',
        '67a0dedfb01706ba4' => 'produit-fire-bowl-gel',
        '67a0dec65233e2e5f' => 'produit-fire-bowl-gelules',
        '6788ed71c64084775' => 'produit-24-carats',
        '6788ee18486669e19' => 'produit-vari-stop',
        '6788f170446c79618' => 'produit-mycose',
        '6788fbdf45d3c1adc' => 'produit-burn-fast', // gel minceur — soft; often no dedicated white
        '6788fd1420a471aeb' => 'produit-cellulite',
        '6788f6c995c5efd48' => 'produit-push-me',
        '6839e75ccf7e7c19b' => 'produit-mega-mass',
        '683dd9f8e31047547' => 'produit-virgin',
        '688c92cf8398a57b7' => 'produit-pure-intima',
        '68b1b9f8600b59a89' => 'produit-respira',
        '68b8768fa4e03e1ae' => 'produit-pump-me',
        '68db9f439ab64654c' => 'produit-acoufin',
        '69088c3ac04014bbd' => 'produit-acoufin-huile',
        '68e502b025e623a63' => 'produit-stopoux',
        '6915993d018876220' => 'produit-blockpoux',
        '69025982ab7e0f1ff' => 'produit-the-shot',
        '6943d6e2204db1790' => 'produit-the-shot',
        '692afefca603a360c' => 'produit-visionet',
        '692c3543e9f261be7' => 'produit-atopi-care',
        '692d7e96b74798d51' => 'produit-hemorrelief',
        '6939aab5b6352a43f' => 'produit-magic-white',
        '6942d045c6b5e9d10' => 'produit-brain-up',
        '69998959c41629065' => 'produit-diabexa',
        '69a17c9b8e09824d0' => 'produit-pack-warrior',
        '69baa8e14e8cb2ade' => 'produit-viriliz',
        '69ef8beb947a0d7e0' => 'produit-growy',
        '69f36e42dd6718ed5' => 'produit-mincimax',
    ];
}

/**
 * Image title / filename needles per espo_id (first match wins).
 *
 * @return array<string,array<int,string>>
 */
function hd_catalog_products_image_needles(): array
{
    return [
        '6788d3529d0999868' => ['MACA STIMUL PRO', 'MACA-STIMUL-PRO'],
        '6788d509d0a719d2f' => ['CLEAN COLON', 'CLEAN-COLON'],
        '68efd25d4513b81eb' => ['DEEP SLEEP', 'DEEP-SLEEP'],
        '68f0f9aa2b4101b36' => ['FULL RELAX', 'FULL-RELAX'],
        '6788dbd56710ce96e' => ['BURN FAST PACK', 'BURN-FAST-PACK', 'BURN FAST CAPSULES'],
        '6788de2054c8ac7ee' => ['PROSTAPRO', 'PROSTA'],
        '6788d9c961fdebcc6' => ['GLORY GEL', 'GLORY-GEL'],
        '6788e52395c10bc46' => ['GLORY GEL', 'GLORY-GEL'],
        '6788e33ce7bab0acd' => ['MACA BOOST WOMEN', 'MACA-BOOST-WOMEN'],
        '679cbd19086e7fe95' => ['HAIR REVIVE pack', 'HAIR-REVIVE-pack', 'HAIR REVIVE'],
        '6794c074dcbf8e186' => ['EL TORO', 'EL-TORO'],
        '6788e923016b676ff' => ['NIGHT SECRET', 'NIGHT-SECRET'],
        '6788fe24f402ceeea' => ['KARDIAX'],
        '6788ec725820aadd3' => ['PURE COLLAGENE PACK', 'PURE-COLLAGENE-PACK', 'COLLAGENE'],
        '67c077f9df5351265' => ['PURE COLLAGENE CAPSULES', 'PURE-COLLAGENE-CAPSULES'],
        '67c077bbea6400ea3' => ['PURE COLLAGENE SERUM', 'PURE-COLLAGENE-SERUM'],
        '6788eb070ad84a92a' => ['firebowl gélules', 'firebowl-gelules', 'FIRE BOWL'],
        '67a0dedfb01706ba4' => ['firebowl GEL', 'firebowl-GEL'],
        '67a0dec65233e2e5f' => ['firebowl gélules', 'firebowl-gelules'],
        '6788ed71c64084775' => ['24 carats', '24-carats'],
        '6788ee18486669e19' => ['VARISTOP'],
        '6788f170446c79618' => ['MYCOSE DES ONGLES', 'MYCOSE'],
        '6788fd1420a471aeb' => ['CELLULITE'],
        '6788f6c995c5efd48' => ['PUSH ME', 'PUSH-ME'],
        '6839e75ccf7e7c19b' => ['MEGA MASS PACK', 'MEGA-MASS-PACK', 'MEGA MASS'],
        '683dd9f8e31047547' => ['virgin', 'VIRGIN'],
        '688c92cf8398a57b7' => ['PURE INTIMA', 'PURE-INTIMA'],
        '68b1b9f8600b59a89' => ['RESPIRA'],
        '68b8768fa4e03e1ae' => ['PUMP ME', 'PUMP-ME'],
        '68db9f439ab64654c' => ['ACOUFIN PACK', 'ACOUFIN'],
        '69088c3ac04014bbd' => ['ACOUFIN HUILE', 'ACOUFIN-HUILE'],
        '68e502b025e623a63' => ['STOPOUX'],
        '6915993d018876220' => ['BLOCKPOUX'],
        '69025982ab7e0f1ff' => ['THE SHOT', 'THE-SHOT'],
        '6943d6e2204db1790' => ['THE SHOT', 'THE-SHOT'],
        '692afefca603a360c' => ['VISIONET'],
        '692c3543e9f261be7' => ['ATOPICARE', 'ATOPI'],
        '692d7e96b74798d51' => ['HEMORRELIEF'],
        '6939aab5b6352a43f' => ['MAGIC WHITE', 'MAGIC-WHITE'],
        '6942d045c6b5e9d10' => ['Brain UP', 'Brain-UP', 'BRAIN'],
        '69998959c41629065' => ['DIABEXA PACK', 'DIABEXA'],
        '69a17c9b8e09824d0' => ['PACK WARRIOR'],
        '69baa8e14e8cb2ade' => ['PACK VIRILIZ', 'VIRILIZ'],
        '69ef8beb947a0d7e0' => ['GROWY'],
        '69f36e42dd6718ed5' => ['MINCIMAX'],
    ];
}

/**
 * Soft-fill empty link fields for all products.
 */
function hd_catalog_products_autolink_all(): void
{
    global $wpdb;
    $table = hd_catalog_products_table();
    $products = $wpdb->get_results("SELECT * FROM {$table}");
    if (!$products) {
        return;
    }

    $formsByProject = hd_catalog_products_index_forms_by_project();
    $formsByMerciYm = hd_catalog_products_index_forms_by_merci_ym();
    $pagesBySlug = hd_catalog_products_index_pages_by_slug();
    $lpByForm = hd_catalog_products_index_lp_by_form();
    $imageIndex = hd_catalog_products_index_attachments();

    $whiteMap = hd_catalog_products_white_slug_map();
    $imageNeedles = hd_catalog_products_image_needles();

    foreach ($products as $p) {
        $update = [];
        $espo = (string) $p->espo_id;
        $sku = strtoupper((string) $p->sku);

        // Form: prefer merci YM slug (more reliable than project_id when CRM IDs drifted),
        // then project_id === espo_id.
        if ((string) $p->form_key === '') {
            $formKey = '';
            $skuYm = '';
            if (preg_match('/^(YM\d+(?:-\d+)?)/i', $sku, $m)) {
                $skuYm = strtolower($m[1]);
                $formKey = $formsByMerciYm[$skuYm] ?? '';
            }
            if ($formKey === '') {
                $formKey = $formsByProject[$espo] ?? '';
            }
            if ($formKey !== '') {
                $update['form_key'] = $formKey;
                $p->form_key = $formKey;
            }
        }

        // White + boutique pages
        if ((int) $p->white_page_id === 0 && isset($whiteMap[$espo])) {
            $slug = $whiteMap[$espo];
            if (isset($pagesBySlug[$slug])) {
                $update['white_page_id'] = (int) $pagesBySlug[$slug];
                // Boutique product pages = white product pages on this site.
                if ((int) $p->boutique_page_id === 0) {
                    $update['boutique_page_id'] = (int) $pagesBySlug[$slug];
                }
            }
        }

        // LP pages from form_key
        $formKey = (string) (($update['form_key'] ?? null) ?: $p->form_key);
        $lpExisting = hd_catalog_products_decode_json($p->lp_page_ids);
        if (!$lpExisting && $formKey !== '' && !empty($lpByForm[$formKey])) {
            $update['lp_page_ids'] = hd_catalog_products_encode_ids($lpByForm[$formKey]);
        }

        // Merci pages from form defs
        $merciExisting = hd_catalog_products_decode_json($p->merci_page_ids);
        if (!$merciExisting && $formKey !== '' && function_exists('hd_native_get_form')) {
            $form = hd_native_get_form($formKey);
            $merciIds = [];
            if (is_array($form)) {
                if (!empty($form['merci_page_id'])) {
                    $merciIds[] = (int) $form['merci_page_id'];
                }
                $merciSlug = (string) ($form['merci_slug'] ?? '');
                if ($merciSlug !== '' && isset($pagesBySlug[$merciSlug])) {
                    $merciIds[] = (int) $pagesBySlug[$merciSlug];
                }
                // Case-insensitive slug variants
                if ($merciSlug !== '') {
                    foreach ($pagesBySlug as $slug => $pid) {
                        if (strcasecmp($slug, $merciSlug) === 0) {
                            $merciIds[] = (int) $pid;
                        }
                    }
                }
            }
            if ($merciIds) {
                $update['merci_page_ids'] = hd_catalog_products_encode_ids($merciIds);
            }
        }

        // Image soft match
        if ((int) $p->image_attachment_id === 0 && !empty($imageNeedles[$espo])) {
            $attId = hd_catalog_products_match_attachment($imageNeedles[$espo], $imageIndex);
            if ($attId > 0) {
                $update['image_attachment_id'] = $attId;
            }
        }

        // project_id default = espo_id
        if ((string) $p->project_id === '') {
            $update['project_id'] = $espo;
        }

        if ($update) {
            $update['updated_at'] = current_time('mysql');
            $wpdb->update($table, $update, ['id' => (int) $p->id]);
        }
    }

    hd_catalog_products_resolve_primary_ids();
}

/**
 * @return array<string,string> project_id => preferred form_key
 */
function hd_catalog_products_index_forms_by_project(): array
{
    $out = [];
    $forms = get_option(defined('HD_LEADS_OPTION') ? HD_LEADS_OPTION : 'hd_native_form_defs', []);
    if (!is_array($forms)) {
        return $out;
    }
    foreach ($forms as $key => $form) {
        $pid = (string) ($form['project_id'] ?? '');
        if ($pid === '') {
            continue;
        }
        // Prefer shorter/canonical form keys (f#### over f####b####).
        if (!isset($out[$pid]) || strlen((string) $key) < strlen($out[$pid])) {
            $out[$pid] = sanitize_key((string) $key);
        }
    }
    return $out;
}

/**
 * @return array<string,string> ym token => form_key
 */
function hd_catalog_products_index_forms_by_merci_ym(): array
{
    $out = [];
    $forms = get_option(defined('HD_LEADS_OPTION') ? HD_LEADS_OPTION : 'hd_native_form_defs', []);
    if (!is_array($forms)) {
        return $out;
    }
    foreach ($forms as $key => $form) {
        $slug = strtolower((string) ($form['merci_slug'] ?? ''));
        if (preg_match('/ym(\d+(?:-\d+)?)/', $slug, $m)) {
            $token = 'ym' . $m[1];
            if (!isset($out[$token])) {
                $out[$token] = sanitize_key((string) $key);
            }
        }
    }
    return $out;
}

/**
 * @return array<string,int> slug => page ID
 */
function hd_catalog_products_index_pages_by_slug(): array
{
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT ID, post_name FROM {$wpdb->posts}
         WHERE post_type = 'page' AND post_status IN ('publish','draft','private')"
    );
    $out = [];
    foreach ($rows as $r) {
        $out[(string) $r->post_name] = (int) $r->ID;
    }
    return $out;
}

/**
 * @return array<string,array<int,int>> form_key => [page ids]
 */
function hd_catalog_products_index_lp_by_form(): array
{
    global $wpdb;
    $out = [];
    $metaKeys = ['hd_boutique_form_key', 'hd_form_key'];
    foreach ($metaKeys as $mk) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, pm.meta_value AS form_key, mt.meta_value AS template
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} mt ON mt.post_id = p.ID AND mt.meta_key = '_wp_page_template'
             WHERE p.post_type = 'page' AND p.post_status IN ('publish','draft','private')",
            $mk
        ));
        foreach ($rows as $r) {
            $fk = sanitize_key((string) $r->form_key);
            if ($fk === '') {
                continue;
            }
            $tpl = (string) ($r->template ?? '');
            if (stripos($tpl, 'landing') === false && stripos($tpl, 'merci') === false) {
                // Prefer landing; still collect non-white if landing-like slug
                if (stripos($tpl, 'white') !== false || stripos($tpl, 'boutique') !== false) {
                    continue;
                }
            }
            if (stripos($tpl, 'merci') !== false) {
                continue;
            }
            if (stripos($tpl, 'landing') !== false || $tpl === '' || $tpl === 'default') {
                if (stripos($tpl, 'landing') !== false) {
                    $out[$fk][] = (int) $r->ID;
                }
            }
        }
    }
    foreach ($out as $fk => $ids) {
        $out[$fk] = array_values(array_unique(array_map('intval', $ids)));
    }
    return $out;
}

/**
 * @return array<int,array{id:int,title:string,file:string}>
 */
function hd_catalog_products_index_attachments(): array
{
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT p.ID, p.post_title, pm.meta_value AS file
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
         WHERE p.post_type = 'attachment'
           AND (pm.meta_value LIKE '%.png' OR pm.meta_value LIKE '%.webp' OR pm.meta_value LIKE '%.jpg')"
    );
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int) $r->ID,
            'title' => (string) $r->post_title,
            'file' => (string) $r->file,
        ];
    }
    return $out;
}

/**
 * @param array<int,string> $needles
 * @param array<int,array{id:int,title:string,file:string}> $index
 */
function hd_catalog_products_match_attachment(array $needles, array $index): int
{
    foreach ($needles as $needle) {
        $n = strtolower($needle);
        foreach ($index as $att) {
            $hay = strtolower($att['title'] . ' ' . $att['file']);
            if (strpos($hay, $n) !== false) {
                // Prefer exact-ish title match over pack variants when needle is short.
                return (int) $att['id'];
            }
        }
    }
    return 0;
}
