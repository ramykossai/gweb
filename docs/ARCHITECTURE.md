# Architecture GWEB / HD stack

## Flux conversion

```
Meta Ads (URL ?page_id=SOURCE&utm_*)
    → hd-page-id-router (map SOURCE → WP page)
    → LP (theme hd-lite / template HD Landing)
    → Pixel browser (header.php) PageView ×1
    → Popup form [hd_lp_popup] / native lead
    → wp_hd_leads (+ meta fbp/fbc/utm via enrich)
    → EspoCRM LeadCapture + webhook map.hvserver
    → Page merci (custom conversion Meta URL)
```

## Composants mu-plugins

### `hd-native-leads`
- Définitions forms (`hd_native_form_defs`) : projet Espo, webhook, merci, labels AR/FR.
- Stockage local `wp_hd_leads`.
- Drafts, retry Espo, trackingcomment vendeur.
- **Ne versionne pas** les rows leads.

### `hd-meta-capi`
- Historiquement CAPI → pixel Rockabilly only.
- En GWEB/HD : **`enabled=false`** ; on garde le filtre `hd_native_lead_meta` pour cookies browser.
- Ne pas supprimer le loader mu-plugin sans déplacer l’enrich ailleurs.

### `hd-page-id-router`
- Option `hd_source_page_map` : `[ source_page_id => wp_post_id ]`.
- Exemple : ads `page_id=2461` → WP `116` (FIRE BOWL V4).
- Normalise aussi les URLs ads cassées (`page_id=X/?page_id=Y`).

### `tb-tracker`
- Clicks / events sampling (`tb_tracker_sample_rate`, souvent 20).
- Sert CR interne (leads/clicks) — **données hors git**.

### `hd-catalog-products`
- Table `wp_hd_catalog_products` : SKU, pages LP/boutique, form_key.
- Admin soft-links produits ↔ forms.

### `hd-sec-harden` / Hostinger mu
- Durcissement / auto-updates Hostinger (optionnel hors Hostinger).

## Theme `hd-lite`

- Templates : `page-hd-landing.php`, `page-hd-boutique.php`, `page-hd-merci.php`, …
- **Pixel uniquement dans `header.php`** (pas dans les white HTML).
- Shortcode popup embarqué dans le contenu LP.

## Cloak (`hd-cloak-links`)

- Pays autorisés typiques : `MA,DZ,TN`.
- Block bots / ASN datacenter.
- Les hits `facebookexternalhit` (preview) peuvent apparaître en geo US — normal.

## Pixel Meta (contrat)

```
1× fbevents.js (guard if(f.fbq)return)
N× fbq('init', PIXEL_ID)   // primary FIRST
1× fbq('track', 'PageView')
```

Attribution ads : browser + custom conversion URL merci.  
CAPI Rocka n’est **pas** le signal principal.

## Données

| Donnée | Git | VPS |
|--------|-----|-----|
| Code theme/mu-plugins | ✅ | ✅ |
| Posts / catalog / white / uploads | ✅ | ✅ |
| Options forms/map/cloak | ✅ (sanitized) | ✅ |
| Leads + tracker rows | ❌ | ✅ only |
| Tokens | ❌ | ✅ only |
