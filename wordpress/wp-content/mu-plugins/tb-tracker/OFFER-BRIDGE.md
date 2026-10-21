# Bridge `offer_slug` cloak → TB Tracker

## Contrat

- Sur un trafic **allow** :
  - **redirect** : le cloak ajoute `tb_offer={code}` à l’URL de la LP.
  - **inline** : pas de redirect → le cloak injecte `$_GET['tb_offer']` et
    déclenche `hd_cloak_serving_inline_offer` **avant** l’include du template LP.
- TB Tracker (`tb_tracker_bootstrap_front_hit`) pose le cookie `tbclid`,
  enregistre `page_view` + UTM/fbclid, remplit `offer_slug` (first-touch),
  puis `tb.js` peut s’enqueue via `wp_head` du template.
- Première valeur non vide de `offer_slug` gagne.
- Aucun appel SQL lourd depuis le filtre allow/block ; uniquement après décision allow.

## Activation

```php
// wp-config.php
define('HD_CLOAK_TB_OFFER_BRIDGE', true);
```

+ `tb_tracker_enabled` + (recommandé) `tb_js_engagement` / `tb_js_context`.

## Mode inline (smart links type `/c/anaxne`)

Le cloak sort à `template_redirect` priorité 0 (avant le hook tracker prio 20).
Sans le hook `hd_cloak_serving_inline_offer`, le tracker ne voyait jamais ces hits.
Depuis v1.3.2 / tracker 0.3.2, l’inline est supporté de bout en bout.

White pages en HTML statique (`white/{slug}/site.html`) restent hors tracker
(fichier plat, pas de WP footer).
