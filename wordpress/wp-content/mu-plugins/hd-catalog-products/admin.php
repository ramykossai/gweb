<?php
/**
 * HD Catalog Products — admin UI (liste + fiche CRUD).
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'hd_catalog_products_admin_menu');
add_action('admin_enqueue_scripts', 'hd_catalog_products_admin_assets');
add_action('admin_post_hd_catalog_product_save', 'hd_catalog_products_handle_save');
add_action('admin_post_hd_catalog_product_delete', 'hd_catalog_products_handle_delete');
add_action('admin_post_hd_catalog_product_autolink', 'hd_catalog_products_handle_autolink');
add_action('admin_post_hd_catalog_product_reseed', 'hd_catalog_products_handle_reseed');

function hd_catalog_products_admin_menu(): void
{
    add_menu_page(
        'Produits',
        'Produits',
        'manage_options',
        'hd-catalog-products',
        'hd_catalog_products_admin_render',
        'dashicons-products',
        58
    );
    // Soft discoverability under Leads as well.
    add_submenu_page(
        'hd-native-leads',
        'Produits',
        'Produits',
        'manage_options',
        'hd-catalog-products',
        'hd_catalog_products_admin_render'
    );
}

function hd_catalog_products_admin_assets(string $hook): void
{
    if (strpos($hook, 'hd-catalog-products') === false) {
        return;
    }
    $cssFile = HD_CATALOG_PRODUCTS_DIR . '/assets/admin.css';
    $css = is_readable($cssFile) ? (string) file_get_contents($cssFile) : '';
    wp_register_style('hd-catalog-products-admin', false, [], HD_CATALOG_PRODUCTS_VERSION);
    wp_enqueue_style('hd-catalog-products-admin');
    if ($css !== '') {
        wp_add_inline_style('hd-catalog-products-admin', $css);
    }
    // Google fonts (Syne + DM Sans) — soft, non-blocking.
    wp_enqueue_style(
        'hd-catalog-products-fonts',
        'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700&family=Syne:wght@600;700&display=swap',
        [],
        null
    );
    wp_enqueue_media();
}

function hd_catalog_products_admin_render(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Accès refusé.', 403);
    }

    $view = sanitize_key($_GET['view'] ?? 'list');
    $id = absint($_GET['id'] ?? 0);

    if ($view === 'edit' || $view === 'new') {
        hd_catalog_products_render_edit($view === 'new' ? 0 : $id);
        return;
    }

    hd_catalog_products_render_list();
}

function hd_catalog_products_render_list(): void
{
    global $wpdb;
    $table = hd_catalog_products_table();
    $stats = hd_catalog_products_overview_stats();
    $q = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
    $filter = sanitize_key($_GET['filter'] ?? '');

    $where = '1=1';
    $args = [];
    if ($q !== '') {
        $like = '%' . $wpdb->esc_like($q) . '%';
        $where .= ' AND (name LIKE %s OR sku LIKE %s OR espo_id LIKE %s OR form_key LIKE %s)';
        $args = array_merge($args, [$like, $like, $like, $like]);
    }
    if ($filter === 'form') {
        $where .= " AND form_key <> ''";
    } elseif ($filter === 'boutique') {
        $where .= ' AND (boutique_page_id > 0 OR white_page_id > 0)';
    } elseif ($filter === 'orphan') {
        $where .= " AND form_key = '' AND boutique_page_id = 0 AND white_page_id = 0";
    } elseif ($filter === 'variant') {
        $where .= " AND (parent_espo_id <> '' OR primary_product_id > 0)";
    } elseif ($filter === 'draft') {
        $where .= " AND status = 'draft'";
    }

    $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY sku ASC, name ASC";
    $rows = $args
        ? $wpdb->get_results($wpdb->prepare($sql, $args))
        : $wpdb->get_results($sql);

    $forms = get_option(defined('HD_LEADS_OPTION') ? HD_LEADS_OPTION : 'hd_native_form_defs', []);
    if (!is_array($forms)) {
        $forms = [];
    }

    $notice = sanitize_key($_GET['hdcp_notice'] ?? '');
    ?>
    <div class="wrap hdcp-wrap">
        <div class="hdcp-hero">
            <div>
                <p class="hdcp-eyebrow">Catalogue CRM</p>
                <h1 class="hdcp-title">Produits</h1>
                <p class="hdcp-sub">Table locale liée soft aux forms HD, pages boutique / white, LP et merci. Aucun cutover.</p>
            </div>
            <div class="hdcp-hero-actions">
                <a class="hdcp-btn hdcp-btn--primary" href="<?php echo esc_url(admin_url('admin.php?page=hd-catalog-products&view=new')); ?>">+ Ajouter</a>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hdcp-inline-form">
                    <?php wp_nonce_field('hd_catalog_product_autolink'); ?>
                    <input type="hidden" name="action" value="hd_catalog_product_autolink" />
                    <button type="submit" class="hdcp-btn">Relancer auto-link</button>
                </form>
            </div>
        </div>

        <?php if ($notice === 'saved') : ?>
            <div class="hdcp-notice hdcp-notice--ok">Produit enregistré.</div>
        <?php elseif ($notice === 'deleted') : ?>
            <div class="hdcp-notice hdcp-notice--ok">Produit supprimé.</div>
        <?php elseif ($notice === 'autolink') : ?>
            <div class="hdcp-notice hdcp-notice--ok">Auto-link soft exécuté.</div>
        <?php elseif ($notice === 'reseed') : ?>
            <div class="hdcp-notice hdcp-notice--ok">Seed Espo synchronisé.</div>
        <?php endif; ?>

        <div class="hdcp-stats">
            <div class="hdcp-stat"><span class="hdcp-stat__n"><?php echo (int) $stats['total']; ?></span><span class="hdcp-stat__l">Produits</span></div>
            <div class="hdcp-stat"><span class="hdcp-stat__n"><?php echo (int) $stats['with_form']; ?></span><span class="hdcp-stat__l">Avec form</span></div>
            <div class="hdcp-stat"><span class="hdcp-stat__n"><?php echo (int) $stats['with_boutique']; ?></span><span class="hdcp-stat__l">Boutique / white</span></div>
            <div class="hdcp-stat"><span class="hdcp-stat__n"><?php echo (int) $stats['orphans']; ?></span><span class="hdcp-stat__l">Orphelins</span></div>
            <div class="hdcp-stat"><span class="hdcp-stat__n"><?php echo (int) $stats['leads_7d']; ?></span><span class="hdcp-stat__l">Leads 7j</span></div>
        </div>

        <form method="get" class="hdcp-toolbar">
            <input type="hidden" name="page" value="hd-catalog-products" />
            <input type="search" name="q" value="<?php echo esc_attr($q); ?>" placeholder="SKU, nom, espo_id, form…" class="hdcp-search" />
            <select name="filter" class="hdcp-select">
                <option value="">Tous</option>
                <option value="form" <?php selected($filter, 'form'); ?>>Avec form</option>
                <option value="boutique" <?php selected($filter, 'boutique'); ?>>Boutique / white</option>
                <option value="variant" <?php selected($filter, 'variant'); ?>>Variantes</option>
                <option value="orphan" <?php selected($filter, 'orphan'); ?>>Orphelins</option>
                <option value="draft" <?php selected($filter, 'draft'); ?>>Draft</option>
            </select>
            <button type="submit" class="hdcp-btn">Filtrer</button>
        </form>

        <div class="hdcp-grid">
            <?php if (!$rows) : ?>
                <p class="hdcp-empty">Aucun produit. Le seed Espo se charge au boot — sinon utilisez « Reseed ».</p>
            <?php endif; ?>
            <?php foreach ($rows as $row) :
                $img = (int) $row->image_attachment_id > 0
                    ? wp_get_attachment_image_url((int) $row->image_attachment_id, 'thumbnail')
                    : '';
                $editUrl = admin_url('admin.php?page=hd-catalog-products&view=edit&id=' . (int) $row->id);
                $leads = $row->form_key !== '' ? hd_catalog_products_leads_count((string) $row->form_key, 7) : 0;
                $isVariant = ((string) $row->parent_espo_id !== '' || (int) $row->primary_product_id > 0);
                ?>
                <a class="hdcp-card" href="<?php echo esc_url($editUrl); ?>">
                    <div class="hdcp-card__media">
                        <?php if ($img) : ?>
                            <img src="<?php echo esc_url($img); ?>" alt="" />
                        <?php else : ?>
                            <div class="hdcp-card__ph"><?php echo esc_html(substr((string) $row->sku, 0, 4)); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="hdcp-card__body">
                        <div class="hdcp-card__sku"><?php echo esc_html($row->sku); ?></div>
                        <div class="hdcp-card__name"><?php echo esc_html($row->name); ?></div>
                        <div class="hdcp-card__prices"><?php echo esc_html(hd_catalog_products_prices_label($row->prices_json)); ?> DT</div>
                        <div class="hdcp-badges">
                            <?php if ($row->form_key) : ?><span class="hdcp-badge hdcp-badge--ok">form</span><?php else : ?><span class="hdcp-badge">form?</span><?php endif; ?>
                            <?php if ((int) $row->white_page_id || (int) $row->boutique_page_id) : ?><span class="hdcp-badge hdcp-badge--ok">boutique</span><?php else : ?><span class="hdcp-badge">boutique?</span><?php endif; ?>
                            <?php
                            $lps = hd_catalog_products_decode_json($row->lp_page_ids);
                            if ($lps) : ?><span class="hdcp-badge hdcp-badge--ok">LP</span><?php else : ?><span class="hdcp-badge">LP?</span><?php endif; ?>
                            <?php if ($isVariant) : ?><span class="hdcp-badge hdcp-badge--amber">variante</span><?php endif; ?>
                            <?php if ($row->status === 'draft') : ?><span class="hdcp-badge hdcp-badge--muted">draft</span><?php endif; ?>
                        </div>
                        <?php if ($row->form_key) : ?>
                            <div class="hdcp-card__meta"><?php echo esc_html($row->form_key); ?> · <?php echo (int) $leads; ?> leads / 7j</div>
                        <?php elseif ($isVariant) : ?>
                            <div class="hdcp-card__meta">Parent: <?php echo esc_html($row->parent_espo_id ?: ('#' . $row->primary_product_id)); ?></div>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="hdcp-footer-tools">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('hd_catalog_product_reseed'); ?>
                <input type="hidden" name="action" value="hd_catalog_product_reseed" />
                <button type="submit" class="hdcp-btn hdcp-btn--ghost" onclick="return confirm('Synchroniser les lignes Espo manquantes / refresh seed ?');">Reseed Espo</button>
            </form>
        </div>
    </div>
    <?php
}

/**
 * @return array<int,string> id => label
 */
function hd_catalog_products_page_choices(): array
{
    $pages = get_pages([
        'sort_column' => 'post_title',
        'sort_order' => 'ASC',
        'post_status' => ['publish', 'draft', 'private'],
    ]);
    $out = [0 => '— Aucune —'];
    foreach ($pages as $p) {
        $out[(int) $p->ID] = $p->post_title . ' (#' . $p->ID . ')';
    }
    return $out;
}

/**
 * @return array<string,string>
 */
function hd_catalog_products_form_choices(): array
{
    $forms = get_option(defined('HD_LEADS_OPTION') ? HD_LEADS_OPTION : 'hd_native_form_defs', []);
    $out = ['' => '— Aucun —'];
    if (!is_array($forms)) {
        return $out;
    }
    foreach ($forms as $key => $form) {
        $title = (string) ($form['title'] ?? $form['form_title'] ?? $key);
        $out[sanitize_key((string) $key)] = $title . ' (' . $key . ')';
    }
    asort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/**
 * @return array<int,string>
 */
function hd_catalog_products_parent_choices(int $excludeId = 0): array
{
    global $wpdb;
    $table = hd_catalog_products_table();
    $rows = $wpdb->get_results("SELECT id, sku, name, espo_id FROM {$table} ORDER BY sku ASC, name ASC");
    $out = [0 => '— Aucun (produit principal) —'];
    foreach ($rows as $r) {
        if ((int) $r->id === $excludeId) {
            continue;
        }
        $out[(int) $r->id] = $r->sku . ' — ' . $r->name . ' [' . $r->espo_id . ']';
    }
    return $out;
}

function hd_catalog_products_render_edit(int $id): void
{
    $isNew = $id <= 0;
    $row = $isNew ? null : hd_catalog_products_get($id);
    if (!$isNew && !$row) {
        echo '<div class="wrap"><p>Produit introuvable.</p></div>';
        return;
    }

    $defaults = [
        'espo_id' => '',
        'sku' => '',
        'name' => '',
        'prices_json' => '[]',
        'image_attachment_id' => 0,
        'boutique_page_id' => 0,
        'white_page_id' => 0,
        'form_key' => '',
        'project_id' => '',
        'lp_page_ids' => '[]',
        'merci_page_ids' => '[]',
        'parent_espo_id' => '',
        'primary_product_id' => 0,
        'status' => 'active',
        'notes' => '',
    ];
    $data = $row ? (array) $row : $defaults;
    $prices = hd_catalog_products_decode_json($data['prices_json'] ?? '[]');
    $priceCsv = [];
    foreach ($prices as $t) {
        $priceCsv[] = (string) ((float) ($t['price'] ?? 0));
    }
    $lpIds = hd_catalog_products_decode_json($data['lp_page_ids'] ?? '[]');
    $merciIds = hd_catalog_products_decode_json($data['merci_page_ids'] ?? '[]');
    $imgId = (int) ($data['image_attachment_id'] ?? 0);
    $imgUrl = $imgId ? wp_get_attachment_image_url($imgId, 'medium') : '';

    $pages = hd_catalog_products_page_choices();
    $forms = hd_catalog_products_form_choices();
    $parents = hd_catalog_products_parent_choices($id);
    $listUrl = admin_url('admin.php?page=hd-catalog-products');
    $leads7 = !empty($data['form_key']) ? hd_catalog_products_leads_count((string) $data['form_key'], 7) : 0;
    $leadsAll = !empty($data['form_key']) ? hd_catalog_products_leads_count((string) $data['form_key'], 0) : 0;
    ?>
    <div class="wrap hdcp-wrap">
        <div class="hdcp-hero hdcp-hero--edit">
            <div>
                <p class="hdcp-eyebrow"><a href="<?php echo esc_url($listUrl); ?>">← Produits</a></p>
                <h1 class="hdcp-title"><?php echo $isNew ? 'Nouveau produit' : esc_html((string) $data['name']); ?></h1>
                <?php if (!$isNew) : ?>
                    <p class="hdcp-sub"><?php echo esc_html((string) $data['sku']); ?> · espo <code><?php echo esc_html((string) $data['espo_id']); ?></code></p>
                <?php endif; ?>
            </div>
            <?php if (!$isNew && !empty($data['form_key'])) : ?>
                <div class="hdcp-stat hdcp-stat--solo">
                    <span class="hdcp-stat__n"><?php echo (int) $leads7; ?></span>
                    <span class="hdcp-stat__l">Leads 7j · <?php echo (int) $leadsAll; ?> total</span>
                </div>
            <?php endif; ?>
        </div>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hdcp-form">
            <?php wp_nonce_field('hd_catalog_product_save'); ?>
            <input type="hidden" name="action" value="hd_catalog_product_save" />
            <input type="hidden" name="id" value="<?php echo (int) $id; ?>" />

            <div class="hdcp-form-grid">
                <section class="hdcp-panel">
                    <h2>Identité</h2>
                    <label class="hdcp-field">
                        <span>Espo ID *</span>
                        <input name="espo_id" required value="<?php echo esc_attr((string) $data['espo_id']); ?>" <?php disabled(!$isNew); ?> />
                        <?php if (!$isNew) : ?><input type="hidden" name="espo_id" value="<?php echo esc_attr((string) $data['espo_id']); ?>" /><?php endif; ?>
                    </label>
                    <label class="hdcp-field">
                        <span>SKU / code YM</span>
                        <input name="sku" value="<?php echo esc_attr((string) $data['sku']); ?>" placeholder="YM01" />
                    </label>
                    <label class="hdcp-field">
                        <span>Nom</span>
                        <input name="name" required value="<?php echo esc_attr((string) $data['name']); ?>" />
                    </label>
                    <label class="hdcp-field">
                        <span>Prix paliers (CSV: 1u, 2u, 3u)</span>
                        <input name="prices_csv" value="<?php echo esc_attr(implode(', ', $priceCsv)); ?>" placeholder="79, 119, 139" />
                    </label>
                    <label class="hdcp-field">
                        <span>Statut</span>
                        <select name="status">
                            <option value="active" <?php selected($data['status'], 'active'); ?>>active</option>
                            <option value="draft" <?php selected($data['status'], 'draft'); ?>>draft</option>
                        </select>
                    </label>
                    <label class="hdcp-field">
                        <span>Notes</span>
                        <textarea name="notes" rows="3"><?php echo esc_textarea((string) ($data['notes'] ?? '')); ?></textarea>
                    </label>
                </section>

                <section class="hdcp-panel">
                    <h2>Image</h2>
                    <div class="hdcp-image-picker">
                        <div class="hdcp-image-preview" id="hdcp-image-preview">
                            <?php if ($imgUrl) : ?>
                                <img src="<?php echo esc_url($imgUrl); ?>" alt="" />
                            <?php else : ?>
                                <span>Aucune image</span>
                            <?php endif; ?>
                        </div>
                        <input type="hidden" name="image_attachment_id" id="hdcp-image-id" value="<?php echo (int) $imgId; ?>" />
                        <button type="button" class="hdcp-btn" id="hdcp-pick-image">Choisir dans la médiathèque</button>
                        <button type="button" class="hdcp-btn hdcp-btn--ghost" id="hdcp-clear-image">Retirer</button>
                    </div>
                </section>

                <section class="hdcp-panel">
                    <h2>Liens soft</h2>
                    <label class="hdcp-field">
                        <span>Form HD (form_key)</span>
                        <select name="form_key">
                            <?php foreach ($forms as $fk => $label) : ?>
                                <option value="<?php echo esc_attr($fk); ?>" <?php selected((string) $data['form_key'], $fk); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="hdcp-field">
                        <span>Project ID CRM</span>
                        <input name="project_id" value="<?php echo esc_attr((string) $data['project_id']); ?>" />
                    </label>
                    <label class="hdcp-field">
                        <span>Page boutique</span>
                        <select name="boutique_page_id">
                            <?php foreach ($pages as $pid => $label) : ?>
                                <option value="<?php echo (int) $pid; ?>" <?php selected((int) $data['boutique_page_id'], (int) $pid); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="hdcp-field">
                        <span>White page</span>
                        <select name="white_page_id">
                            <?php foreach ($pages as $pid => $label) : ?>
                                <option value="<?php echo (int) $pid; ?>" <?php selected((int) $data['white_page_id'], (int) $pid); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="hdcp-field">
                        <span>LP page IDs (virgules)</span>
                        <input name="lp_page_ids" value="<?php echo esc_attr(implode(', ', $lpIds)); ?>" placeholder="123, 456" />
                    </label>
                    <label class="hdcp-field">
                        <span>Merci page IDs (virgules)</span>
                        <input name="merci_page_ids" value="<?php echo esc_attr(implode(', ', $merciIds)); ?>" placeholder="789" />
                    </label>
                </section>

                <section class="hdcp-panel">
                    <h2>Variante / parent</h2>
                    <p class="hdcp-help">Si ce produit a une fiche boutique mais pas de form/LP/projet, router vers le produit principal (ex. Fire Bowl Gel → YM15 Fire Bowl).</p>
                    <label class="hdcp-field">
                        <span>Produit parent</span>
                        <select name="primary_product_id" id="hdcp-primary-product">
                            <?php foreach ($parents as $pid => $label) : ?>
                                <option value="<?php echo (int) $pid; ?>" <?php selected((int) $data['primary_product_id'], (int) $pid); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="hdcp-field">
                        <span>Parent Espo ID (auto si parent choisi)</span>
                        <input name="parent_espo_id" id="hdcp-parent-espo" value="<?php echo esc_attr((string) $data['parent_espo_id']); ?>" />
                    </label>
                </section>
            </div>

            <div class="hdcp-form-actions">
                <button type="submit" class="hdcp-btn hdcp-btn--primary">Enregistrer</button>
                <a class="hdcp-btn" href="<?php echo esc_url($listUrl); ?>">Annuler</a>
            </div>
        </form>
        <?php if (!$isNew) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hdcp-footer-tools" style="margin-left:12px;">
                <?php wp_nonce_field('hd_catalog_product_delete'); ?>
                <input type="hidden" name="action" value="hd_catalog_product_delete" />
                <input type="hidden" name="id" value="<?php echo (int) $id; ?>" />
                <button type="submit" class="hdcp-btn hdcp-btn--danger" onclick="return confirm('Supprimer ce produit ?');">Supprimer</button>
            </form>
        <?php endif; ?>
    </div>
    <script>
    (function () {
      var pick = document.getElementById('hdcp-pick-image');
      var clear = document.getElementById('hdcp-clear-image');
      var input = document.getElementById('hdcp-image-id');
      var preview = document.getElementById('hdcp-image-preview');
      if (pick && typeof wp !== 'undefined' && wp.media) {
        var frame;
        pick.addEventListener('click', function (e) {
          e.preventDefault();
          if (frame) { frame.open(); return; }
          frame = wp.media({ title: 'Image produit', button: { text: 'Utiliser' }, multiple: false });
          frame.on('select', function () {
            var att = frame.state().get('selection').first().toJSON();
            input.value = att.id;
            preview.innerHTML = '<img src="' + (att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url) + '" alt="" />';
          });
          frame.open();
        });
      }
      if (clear) {
        clear.addEventListener('click', function (e) {
          e.preventDefault();
          input.value = '0';
          preview.innerHTML = '<span>Aucune image</span>';
        });
      }
    })();
    </script>
    <?php
}

function hd_catalog_products_handle_save(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Accès refusé.', 403);
    }
    check_admin_referer('hd_catalog_product_save');

    global $wpdb;
    $table = hd_catalog_products_table();
    $id = absint($_POST['id'] ?? 0);
    $pricesCsv = sanitize_text_field(wp_unslash($_POST['prices_csv'] ?? ''));
    $priceParts = preg_split('/[\s,;]+/', $pricesCsv) ?: [];
    $priceParts = array_values(array_filter($priceParts, static function ($v) {
        return $v !== '' && is_numeric(str_replace(',', '.', $v));
    }));

    $primaryId = absint($_POST['primary_product_id'] ?? 0);
    $parentEspo = sanitize_text_field(wp_unslash($_POST['parent_espo_id'] ?? ''));
    if ($primaryId > 0 && $parentEspo === '') {
        $parent = hd_catalog_products_get($primaryId);
        if ($parent) {
            $parentEspo = (string) $parent->espo_id;
        }
    }

    $payload = hd_catalog_products_sanitize_row([
        'espo_id' => wp_unslash($_POST['espo_id'] ?? ''),
        'sku' => wp_unslash($_POST['sku'] ?? ''),
        'name' => wp_unslash($_POST['name'] ?? ''),
        'prices_json' => hd_catalog_products_build_prices($priceParts),
        'image_attachment_id' => $_POST['image_attachment_id'] ?? 0,
        'boutique_page_id' => $_POST['boutique_page_id'] ?? 0,
        'white_page_id' => $_POST['white_page_id'] ?? 0,
        'form_key' => $_POST['form_key'] ?? '',
        'project_id' => wp_unslash($_POST['project_id'] ?? ''),
        'lp_page_ids' => wp_unslash($_POST['lp_page_ids'] ?? ''),
        'merci_page_ids' => wp_unslash($_POST['merci_page_ids'] ?? ''),
        'parent_espo_id' => $parentEspo,
        'primary_product_id' => $primaryId,
        'status' => $_POST['status'] ?? 'active',
        'notes' => wp_unslash($_POST['notes'] ?? ''),
    ]);

    if ($payload['espo_id'] === '' || $payload['name'] === '') {
        wp_die('espo_id et name sont requis.', 400);
    }

    $now = current_time('mysql');
    if ($id > 0) {
        unset($payload['espo_id']); // immutable
        $payload['updated_at'] = $now;
        $wpdb->update($table, $payload, ['id' => $id]);
    } else {
        $dup = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE espo_id = %s LIMIT 1",
            $payload['espo_id']
        ));
        if ($dup) {
            wp_die('Cet espo_id existe déjà.', 400);
        }
        $payload['created_at'] = $now;
        $payload['updated_at'] = $now;
        $wpdb->insert($table, $payload);
        $id = (int) $wpdb->insert_id;
    }

    wp_safe_redirect(admin_url('admin.php?page=hd-catalog-products&view=edit&id=' . $id . '&hdcp_notice=saved'));
    exit;
}

function hd_catalog_products_handle_delete(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Accès refusé.', 403);
    }
    check_admin_referer('hd_catalog_product_delete');

    global $wpdb;
    $id = absint($_POST['id'] ?? 0);
    if ($id > 0) {
        $wpdb->delete(hd_catalog_products_table(), ['id' => $id]);
    }
    wp_safe_redirect(admin_url('admin.php?page=hd-catalog-products&hdcp_notice=deleted'));
    exit;
}

function hd_catalog_products_handle_autolink(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Accès refusé.', 403);
    }
    check_admin_referer('hd_catalog_product_autolink');
    hd_catalog_products_autolink_all();
    wp_safe_redirect(admin_url('admin.php?page=hd-catalog-products&hdcp_notice=autolink'));
    exit;
}

function hd_catalog_products_handle_reseed(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Accès refusé.', 403);
    }
    check_admin_referer('hd_catalog_product_reseed');
    hd_catalog_products_seed_upsert(true, true);
    wp_safe_redirect(admin_url('admin.php?page=hd-catalog-products&hdcp_notice=reseed'));
    exit;
}
