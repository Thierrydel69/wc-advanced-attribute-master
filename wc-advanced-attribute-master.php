<?php
/**
 * Plugin Name:       WooCommerce Advanced Attribute Master
 * Plugin URI:        https://example.com/wc-attribute-master
 * Description:       Gestion dynamique des catégories et attributs globaux WooCommerce depuis une interface tabulaire avancée.
 * Version:           1.0.0
 * Author:            Your Name
 * Author URI:        https://example.com
 * License:           GPL-2.0+
 * Text Domain:       wc-aam
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 */

// ============================================================
// 0. SÉCURITÉ : Bloquer l'accès direct au fichier
// ============================================================
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================
// 1. VÉRIFICATION QUE WOOCOMMERCE EST ACTIF
// ============================================================
add_action( 'plugins_loaded', 'wcaam_check_woocommerce' );
function wcaam_check_woocommerce() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>WC Attribute Master :</strong> WooCommerce doit être activé.</p></div>';
        });
        return;
    }
    // Si WooCommerce est là, on initialise tout
    wcaam_init();
}

function wcaam_init() {
    add_action( 'admin_menu',             'wcaam_register_menu' );
    add_action( 'admin_enqueue_scripts',  'wcaam_enqueue_assets' );
    add_action( 'wp_ajax_wcaam_get_products',       'wcaam_ajax_get_products' );
    add_action( 'wp_ajax_wcaam_save_product',        'wcaam_ajax_save_product' );
    add_action( 'wp_ajax_wcaam_bulk_apply',          'wcaam_ajax_bulk_apply' );
    add_action( 'wp_ajax_wcaam_get_term_suggestions','wcaam_ajax_get_term_suggestions' );
    add_action( 'wp_ajax_wcaam_get_attributes',      'wcaam_ajax_get_attributes' );

    // Fix REHub Attribute Groups - front-end uniquement
    if ( ! is_admin() ) {
        add_filter( 'woocommerce_display_product_attributes', 'wcaam_fix_rehub_groups_filter', 5, 2 );
    }
}

/**
 * Fix REHub Attribute Groups pour les 4 attributs texte libre.
 * Bug REHub ligne 762 : compare $attribute_in_group->slug ('pa_avantages')
 * avec $attribute['name'] ('Avantages') -> jamais égaux.
 * Ce filtre remplace le 'name' par le slug WC pour que la comparaison réussisse.
 * S'applique uniquement sur le front-end, ne modifie rien en base.
 */
function wcaam_fix_rehub_groups_filter( $attributes, $product ) {
    if ( empty( $attributes ) || ! is_array( $attributes ) ) {
        return $attributes;
    }

    $name_to_slug = [
        'Avantages'          => 'pa_avantages',
        'Inconvénients'      => 'pa_inconvenients',
        'Fonctionnalités'    => 'pa_fonctionnalites',
        'Fonctionnalités IA' => 'pa_fonctionnalites-ia',
    ];

    $fixed = [];
    foreach ( $attributes as $key => $attribute ) {
        try {
            if ( $attribute instanceof WC_Product_Attribute ) {
                $attr_name   = $attribute->get_name();
                $attr_is_tax = $attribute->is_taxonomy();
            } elseif ( is_array( $attribute ) ) {
                $attr_name   = $attribute['name'] ?? '';
                $attr_is_tax = ! empty( $attribute['is_taxonomy'] );
            } else {
                $fixed[ $key ] = $attribute;
                continue;
            }

            if ( $attr_is_tax || ! isset( $name_to_slug[ $attr_name ] ) ) {
                $fixed[ $key ] = $attribute;
                continue;
            }

            $pa_slug = $name_to_slug[ $attr_name ];

            if ( $attribute instanceof WC_Product_Attribute ) {
                $options = $attribute->get_options();
                $fixed[ $pa_slug ] = [
                    'name'         => $pa_slug,
                    'value'        => is_array( $options ) ? implode( "
", $options ) : (string) $options,
                    'position'     => $attribute->get_position(),
                    'is_visible'   => $attribute->is_visible() ? 1 : 0,
                    'is_variation' => $attribute->is_variation() ? 1 : 0,
                    'is_taxonomy'  => 0,
                ];
            } else {
                $fixed[ $pa_slug ] = array_merge( $attribute, [ 'name' => $pa_slug ] );
            }

        } catch ( Exception $e ) {
            $fixed[ $key ] = $attribute;
        }
    }

    return $fixed;
}

// Exclure le JS du plugin du cache LiteSpeed
add_filter( 'litespeed_optimize_js_excludes', function( $excludes ) {
    $excludes[] = 'wcaam-main.js';
    return $excludes;
});
add_filter( 'litespeed_cache_exc', function( $excludes ) {
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'wc-attribute-master' ) {
        $excludes[] = 'wc-attribute-master';
    }
    return $excludes;
});



// ============================================================
// 2. MENU ADMIN
// ============================================================
function wcaam_register_menu() {
    add_submenu_page(
        'woocommerce',
        'Attribute Master',
        'Attribute Master',
        'manage_woocommerce',
        'wc-attribute-master',
        'wcaam_render_page'
    );
}

// ============================================================
// 3. ASSETS (CSS + JS inline, aucune dépendance externe)
// ============================================================
function wcaam_enqueue_assets( $hook ) {
    if ( 'woocommerce_page_wc-attribute-master' !== $hook ) return;

    // Charger le fichier JS depuis le dossier du plugin
    // Utiliser le timestamp du fichier comme version pour forcer le cache-bust
    $js_path = plugin_dir_path( __FILE__ ) . 'wcaam-main.js';
    $js_url  = plugin_dir_url( __FILE__ ) . 'wcaam-main.js';
    $version = file_exists( $js_path ) ? filemtime( $js_path ) : '1.9.0';

    wp_enqueue_script( 'jquery' );
    wp_enqueue_script( 'wcaam-main', $js_url, [ 'jquery' ], $version, true );

    // Désactiver le cache LiteSpeed sur cette page admin
    add_action( 'admin_head', function() {
        echo '<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">';
        echo '<meta http-equiv="Pragma" content="no-cache">';
    });

    add_action( 'admin_head', 'wcaam_inline_css' );
}

// ============================================================
// 4. PAGE HTML PRINCIPALE
// ============================================================
function wcaam_render_page() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( 'Accès refusé.' );
    }

    // Récupérer tous les attributs globaux WooCommerce
    $all_attributes = wc_get_attribute_taxonomies(); // tableau d'objets

    // Récupérer toutes les catégories produit
    $all_categories = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'orderby'    => 'name',
    ]);
    ?>
    <div class="wrap wcaam-wrap" id="wcaam-app">

        <!-- ===== EN-TÊTE ===== -->
        <div class="wcaam-header">
            <h1 class="wcaam-title">
                <span class="wcaam-icon">⚙</span>
                Attribute Master
            </h1>
            <p class="wcaam-subtitle">Gérez les attributs et catégories de vos produits WooCommerce</p>
        </div>

        <!-- ===== BARRE D'OUTILS ===== -->
        <div class="wcaam-toolbar">

            <!-- Recherche -->
            <div class="wcaam-search-wrap">
                <span class="wcaam-search-icon">🔍</span>
                <input type="text" id="wcaam-search" placeholder="Rechercher un produit..." autocomplete="off">
            </div>

            <!-- Pagination -->
            <div class="wcaam-per-page-wrap">
                <label for="wcaam-per-page">Produits par page :</label>
                <select id="wcaam-per-page">
                    <option value="20">20</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>

            <!-- Sélecteur de colonnes -->
            <div class="wcaam-col-selector-wrap">
                <button id="wcaam-btn-columns" class="wcaam-btn wcaam-btn-secondary">
                    ☰ Colonnes
                </button>
                <div id="wcaam-col-dropdown" class="wcaam-col-dropdown wcaam-hidden">
                    <div class="wcaam-col-dropdown-header">
                        <strong>Colonnes visibles</strong>
                        <button id="wcaam-col-close" class="wcaam-btn-close">✕</button>
                    </div>
                    <div class="wcaam-col-section">
                        <div class="wcaam-col-section-title">Catégories</div>
                        <label class="wcaam-col-item">
                            <input type="checkbox" class="wcaam-col-toggle" data-col="product_cat" checked>
                            Catégories produit
                        </label>
                    </div>
                    <div class="wcaam-col-section">
                        <div class="wcaam-col-section-title">Attributs globaux</div>
                        <?php foreach ( $all_attributes as $attr ) : ?>
                            <label class="wcaam-col-item">
                                <input type="checkbox" class="wcaam-col-toggle"
                                    data-col="pa_<?php echo esc_attr( $attr->attribute_name ); ?>"
                                    data-label="<?php echo esc_attr( $attr->attribute_label ); ?>"
                                    checked>
                                <?php echo esc_html( $attr->attribute_label ); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="wcaam-col-actions">
                        <button id="wcaam-col-add" class="wcaam-btn wcaam-btn-primary wcaam-btn-sm">
                            + Ajouter colonne d'attribut
                        </button>
                    </div>
                </div>
            </div>

            <!-- Bouton reload -->
            <button id="wcaam-btn-reload" class="wcaam-btn wcaam-btn-secondary" title="Recharger">↺ Recharger</button>
        </div>

        <!-- ===== ACTIONS EN MASSE ===== -->
        <div class="wcaam-bulk-bar wcaam-hidden" id="wcaam-bulk-bar">
            <span id="wcaam-bulk-count">0 produit(s) sélectionné(s)</span>
            <button id="wcaam-btn-apply-bulk" class="wcaam-btn wcaam-btn-primary wcaam-btn-sm">
                ✔ Appliquer la ligne maître à la sélection
            </button>
            <button id="wcaam-btn-deselect-all" class="wcaam-btn wcaam-btn-ghost wcaam-btn-sm">
                Tout désélectionner
            </button>
        </div>

        <!-- ===== TABLEAU ===== -->
        <div class="wcaam-table-container">
            <div id="wcaam-loading" class="wcaam-loading">
                <div class="wcaam-spinner"></div>
                <span>Chargement des produits…</span>
            </div>
            <div id="wcaam-table-wrap" class="wcaam-hidden">
                <table class="wcaam-table" id="wcaam-table">
                    <thead id="wcaam-thead"></thead>
                    <tbody id="wcaam-tbody"></tbody>
                </table>
            </div>
            <div id="wcaam-empty" class="wcaam-empty wcaam-hidden">
                <span>Aucun produit trouvé.</span>
            </div>
        </div>

        <!-- ===== PAGINATION ===== -->
        <div class="wcaam-pagination" id="wcaam-pagination"></div>

        <!-- ===== MODAL : Ajouter un attribut ===== -->
        <div id="wcaam-modal-overlay" class="wcaam-modal-overlay wcaam-hidden">
            <div class="wcaam-modal">
                <div class="wcaam-modal-header">
                    <h3>Ajouter une colonne d'attribut</h3>
                    <button id="wcaam-modal-close" class="wcaam-btn-close">✕</button>
                </div>
                <div class="wcaam-modal-body">
                    <label>Choisir un attribut global :</label>
                    <select id="wcaam-modal-attr-select" class="wcaam-select-full">
                        <option value="">— Sélectionner —</option>
                        <?php foreach ( $all_attributes as $attr ) : ?>
                            <option value="pa_<?php echo esc_attr( $attr->attribute_name ); ?>"
                                    data-label="<?php echo esc_attr( $attr->attribute_label ); ?>">
                                <?php echo esc_html( $attr->attribute_label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="wcaam-modal-footer">
                    <button id="wcaam-modal-confirm" class="wcaam-btn wcaam-btn-primary">Ajouter</button>
                    <button id="wcaam-modal-cancel" class="wcaam-btn wcaam-btn-ghost">Annuler</button>
                </div>
            </div>
        </div>


        <!-- Données PHP injectées pour JS -->
        <script>
        var WCAAM = {
            ajaxUrl: "<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>",
            nonce: "<?php echo esc_js( wp_create_nonce( 'wcaam_nonce' ) ); ?>",
            allAttributes: []
        };
        </script>
    </div>
    <?php
}

// ============================================================
// 5. CSS INLINE (scoped à .wcaam-wrap)
// ============================================================
function wcaam_inline_css() {
    ?>
    <style>
    /* ======= VARIABLES ======= */
    .wcaam-wrap {
        --c-bg:        #f8f9fa;
        --c-surface:   #ffffff;
        --c-border:    #e2e8f0;
        --c-primary:   #7c3aed;
        --c-primary-h: #6d28d9;
        --c-success:   #059669;
        --c-danger:    #dc2626;
        --c-warn:      #d97706;
        --c-text:      #1e293b;
        --c-muted:     #64748b;
        --c-new:       #0ea5e9;
        --radius:      6px;
        --shadow:      0 1px 3px rgba(0,0,0,.1);
        font-family:   -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        color:         var(--c-text);
    }

    /* ======= HEADER ======= */
    .wcaam-header { margin-bottom: 16px; }
    .wcaam-title  { display:flex; align-items:center; gap:8px; font-size:22px; font-weight:700; margin:0 0 4px; }
    .wcaam-icon   { font-size:20px; }
    .wcaam-subtitle { color: var(--c-muted); margin:0; font-size:13px; }

    /* ======= TOOLBAR ======= */
    .wcaam-toolbar {
        display: flex; flex-wrap: wrap; gap: 10px; align-items: center;
        background: var(--c-surface); border: 1px solid var(--c-border);
        border-radius: var(--radius); padding: 10px 14px; margin-bottom: 12px;
        box-shadow: var(--shadow);
    }
    .wcaam-search-wrap {
        display:flex; align-items:center; gap:6px;
        background: var(--c-bg); border: 1px solid var(--c-border);
        border-radius: var(--radius); padding: 5px 10px; flex:1; min-width:180px;
    }
    .wcaam-search-icon { color: var(--c-muted); font-size:13px; }
    .wcaam-search-wrap input {
        border:none; background:transparent; outline:none; font-size:13px;
        color: var(--c-text); width:100%;
    }
    .wcaam-per-page-wrap { display:flex; align-items:center; gap:6px; font-size:13px; }
    .wcaam-per-page-wrap select { font-size:13px; border:1px solid var(--c-border); border-radius: var(--radius); padding:4px 6px; }

    /* ======= BOUTONS ======= */
    .wcaam-btn {
        display:inline-flex; align-items:center; gap:4px;
        padding: 6px 12px; border-radius: var(--radius);
        font-size: 13px; font-weight:500; cursor:pointer;
        border: 1px solid transparent; transition: all .15s; white-space:nowrap;
    }
    .wcaam-btn:disabled { opacity:.5; cursor:not-allowed; }
    .wcaam-btn-primary  { background:var(--c-primary); color:#fff; border-color:var(--c-primary); }
    .wcaam-btn-primary:hover:not(:disabled) { background:var(--c-primary-h); border-color:var(--c-primary-h); }
    .wcaam-btn-secondary{ background:#fff; color:var(--c-text); border-color:var(--c-border); }
    .wcaam-btn-secondary:hover { background:var(--c-bg); }
    .wcaam-btn-ghost     { background:transparent; color:var(--c-muted); border-color:var(--c-border); }
    .wcaam-btn-ghost:hover { background:var(--c-bg); }
    .wcaam-btn-success   { background:var(--c-success); color:#fff; }
    .wcaam-btn-success:hover:not(:disabled) { filter:brightness(.92); }
    .wcaam-btn-sm        { padding: 4px 9px; font-size:12px; }
    .wcaam-btn-close     { background:none; border:none; cursor:pointer; font-size:16px; color:var(--c-muted); padding:2px; }
    .wcaam-btn-close:hover { color:var(--c-danger); }

    /* ======= BULK BAR ======= */
    .wcaam-bulk-bar {
        display:flex; flex-wrap:wrap; align-items:center; gap:10px;
        background:#eff6ff; border:1px solid #93c5fd;
        border-radius: var(--radius); padding:8px 14px; margin-bottom:10px; font-size:13px;
    }
    #wcaam-bulk-count { font-weight:600; color:#1d4ed8; }

    /* ======= COL DROPDOWN ======= */
    .wcaam-col-selector-wrap { position:relative; }
    .wcaam-col-dropdown {
        position:absolute; top:calc(100% + 6px); right:0; z-index:9999;
        background: var(--c-surface); border:1px solid var(--c-border);
        border-radius: var(--radius); box-shadow:0 4px 16px rgba(0,0,0,.12);
        width:260px; max-height:400px; overflow-y:auto;
    }
    .wcaam-col-dropdown-header {
        display:flex; justify-content:space-between; align-items:center;
        padding:10px 14px; border-bottom:1px solid var(--c-border);
        font-size:13px; position:sticky; top:0; background:var(--c-surface);
    }
    .wcaam-col-section { padding: 8px 14px 4px; }
    .wcaam-col-section-title { font-size:11px; font-weight:700; text-transform:uppercase; color:var(--c-muted); margin-bottom:6px; letter-spacing:.05em; }
    .wcaam-col-item { display:flex; align-items:center; gap:6px; font-size:13px; margin-bottom:5px; cursor:pointer; }
    .wcaam-col-item input { cursor:pointer; }
    .wcaam-col-actions { padding:10px 14px; border-top:1px solid var(--c-border); }

    /* ======= LAYOUT : écraser les contraintes de l'admin WordPress ======= */
    /* ======= RESET COMPLET — neutralise REHub et tout thème admin ======= */
    /* Cibler avec la plus haute spécificité possible */
    body #wcaam-app,
    body #wcaam-app * {
        box-sizing: border-box !important;
        writing-mode: horizontal-tb !important;
        -webkit-writing-mode: horizontal-tb !important;
        text-orientation: mixed !important;
        direction: ltr !important;
    }

    /* WordPress admin : neutraliser les marges par défaut */
    body.wp-admin #wpcontent        { padding-left: 10px !important; }
    body.wp-admin #wpbody-content   { padding-bottom: 0 !important; }
    body #wcaam-app.wcaam-wrap      { margin-right: 0 !important; max-width: none !important; width: 100% !important; }

    /* ======= CONTENEUR SCROLL HORIZONTAL ======= */
    body #wcaam-app .wcaam-table-container {
        width: 100% !important;
        overflow-x: auto !important;
        overflow-y: visible !important;
        -webkit-overflow-scrolling: touch !important;
        background: #fff !important;
        border: 1px solid #e2e8f0 !important;
        border-radius: 6px !important;
        box-shadow: 0 1px 3px rgba(0,0,0,.1) !important;
    }
    body #wcaam-app .wcaam-table-container::-webkit-scrollbar       { height: 10px !important; }
    body #wcaam-app .wcaam-table-container::-webkit-scrollbar-track { background: #f1f5f9 !important; }
    body #wcaam-app .wcaam-table-container::-webkit-scrollbar-thumb { background: #94a3b8 !important; border-radius: 4px !important; }
    body #wcaam-app .wcaam-table-container::-webkit-scrollbar-thumb:hover { background: #64748b !important; }

    /* ======= TABLEAU — RESET COMPLET ======= */
    body #wcaam-app table.wcaam-table {
        border-collapse: collapse !important;
        border-spacing: 0 !important;
        font-size: 13px !important;
        table-layout: fixed !important;
        width: max-content !important;
        min-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    /* TOUTES les cellules : reset écriture, pas de vertical */
    body #wcaam-app table.wcaam-table th,
    body #wcaam-app table.wcaam-table td {
        writing-mode: horizontal-tb !important;
        -webkit-writing-mode: horizontal-tb !important;
        text-orientation: mixed !important;
        direction: ltr !important;
        padding: 8px 10px !important;
        border-bottom: 1px solid #e2e8f0 !important;
        border-top: none !important;
        border-left: none !important;
        border-right: none !important;
        vertical-align: middle !important;
        text-align: left !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
        height: auto !important;
        line-height: 1.4 !important;
        font-size: 13px !important;
        font-weight: normal !important;
    }

    /* Headers */
    body #wcaam-app table.wcaam-table thead th {
        background: #f8fafc !important;
        font-weight: 700 !important;
        font-size: 11px !important;
        text-transform: uppercase !important;
        letter-spacing: .05em !important;
        color: #64748b !important;
        white-space: nowrap !important;
        height: 36px !important;
        position: sticky !important;
        top: 0 !important;
        z-index: 10 !important;
    }
    body #wcaam-app table.wcaam-table thead th.wcaam-sortable {
        cursor: pointer !important;
        user-select: none !important;
    }
    body #wcaam-app table.wcaam-table thead th.wcaam-sortable:hover {
        background: #f1f5f9 !important;
        color: #334155 !important;
    }
    body #wcaam-app table.wcaam-table thead th .wcaam-sort-icon {
        margin-left: 4px !important;
        opacity: .4 !important;
        font-style: normal !important;
    }
    body #wcaam-app table.wcaam-table thead th.sort-asc .wcaam-sort-icon,
    body #wcaam-app table.wcaam-table thead th.sort-desc .wcaam-sort-icon {
        opacity: 1 !important;
        color: var(--c-primary) !important;
    }

    /* Cellules de données */
    body #wcaam-app table.wcaam-table tbody td {
        white-space: normal !important;
        vertical-align: top !important;
        padding: 6px 8px !important;
        height: auto !important;
    }

    /* États des lignes */
    body #wcaam-app table.wcaam-table tbody tr:hover td       { background: #fafbff !important; }
    body #wcaam-app table.wcaam-table tbody tr.wcaam-row-dirty td    { background: #fffbeb !important; }
    body #wcaam-app table.wcaam-table tbody tr.wcaam-row-selected td { background: #eff6ff !important; }
    body #wcaam-app table.wcaam-table tbody tr.wcaam-row-master td   { background: #f0fdf4 !important; }

    /* ======= LARGEURS FIXES ======= */
    body #wcaam-app table.wcaam-table .wcaam-col-cb {
        width: 36px !important; min-width: 36px !important; max-width: 36px !important;
        position: sticky !important; left: 0 !important; z-index: 6 !important;
        background: #fff !important;
        white-space: nowrap !important;
    }
    body #wcaam-app table.wcaam-table .wcaam-col-img {
        width: 52px !important; min-width: 52px !important; max-width: 52px !important;
        position: sticky !important; left: 36px !important; z-index: 6 !important;
        background: #fff !important;
        white-space: nowrap !important;
    }
    body #wcaam-app table.wcaam-table .wcaam-col-name {
        width: 200px !important; min-width: 200px !important; max-width: 200px !important;
        position: sticky !important; left: 88px !important; z-index: 6 !important;
        background: #fff !important;
        box-shadow: 3px 0 8px rgba(0,0,0,.08) !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
    }
    body #wcaam-app table.wcaam-table .wcaam-col-attr {
        width: 180px !important; min-width: 180px !important; max-width: 180px !important;
        vertical-align: top !important;
        white-space: normal !important;
    }
    body #wcaam-app table.wcaam-table .wcaam-col-act {
        width: 90px !important; min-width: 90px !important; max-width: 90px !important;
        text-align: center !important;
        position: sticky !important; right: 0 !important; z-index: 4 !important;
        background: #fff !important;
        box-shadow: -2px 0 6px rgba(0,0,0,.07) !important;
        white-space: nowrap !important;
    }

    /* Sticky selon état ligne */
    body #wcaam-app table.wcaam-table .wcaam-row-master .wcaam-col-cb,
    body #wcaam-app table.wcaam-table .wcaam-row-master .wcaam-col-img,
    body #wcaam-app table.wcaam-table .wcaam-row-master .wcaam-col-name,
    body #wcaam-app table.wcaam-table .wcaam-row-master .wcaam-col-act  { background: #f0fdf4 !important; }
    body #wcaam-app table.wcaam-table .wcaam-row-selected .wcaam-col-cb,
    body #wcaam-app table.wcaam-table .wcaam-row-selected .wcaam-col-img,
    body #wcaam-app table.wcaam-table .wcaam-row-selected .wcaam-col-name,
    body #wcaam-app table.wcaam-table .wcaam-row-selected .wcaam-col-act { background: #eff6ff !important; }
    body #wcaam-app table.wcaam-table .wcaam-row-dirty .wcaam-col-cb,
    body #wcaam-app table.wcaam-table .wcaam-row-dirty .wcaam-col-img,
    body #wcaam-app table.wcaam-table .wcaam-row-dirty .wcaam-col-name,
    body #wcaam-app table.wcaam-table .wcaam-row-dirty .wcaam-col-act   { background: #fffbeb !important; }

    #wcaam-app .wcaam-row-selected .wcaam-col-cb,
    #wcaam-app .wcaam-row-selected .wcaam-col-img,
    #wcaam-app .wcaam-row-selected .wcaam-col-name,
    #wcaam-app .wcaam-row-selected .wcaam-col-act  { background: #eff6ff !important; }

    #wcaam-app .wcaam-row-dirty    .wcaam-col-cb,
    #wcaam-app .wcaam-row-dirty    .wcaam-col-img,
    #wcaam-app .wcaam-row-dirty    .wcaam-col-name,
    #wcaam-app .wcaam-row-dirty    .wcaam-col-act  { background: #fffbeb !important; }

    /* ======= PRODUIT : nom + sku ======= */
    .wcaam-product-name { font-weight: 600; color: var(--c-text); font-size: 13px; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .wcaam-product-sku  { font-size: 11px; color: var(--c-muted); display: block; }
    .wcaam-product-img  { width: 38px; height: 38px; object-fit: cover; border-radius: 4px; display: block; }
    .wcaam-product-no-img { width: 38px; height: 38px; background: var(--c-bg); border: 1px dashed var(--c-border); border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 16px; }

    /* ======= TAGINPUT (multi-valeurs) ======= */
    .wcaam-taginput,
    .wcaam-taginput *,
    .wcaam-tag,
    .wcaam-tag *,
    .wcaam-taginput-field {
        writing-mode: horizontal-tb !important;
        -webkit-writing-mode: horizontal-tb !important;
        text-orientation: mixed !important;
        direction: ltr !important;
    }
    .wcaam-taginput {
        position: relative !important;
        display: flex !important;
        flex-direction: column !important;
        gap: 3px !important;
        align-items: stretch !important;
        min-height: 30px !important;
        width: 100% !important;
        max-width: 100% !important;
        border: 1px solid var(--c-border) !important;
        border-radius: var(--radius) !important;
        padding: 3px 6px !important;
        background: #fff !important;
        cursor: text !important;
        box-sizing: border-box !important;
        overflow: visible !important;
    }
    .wcaam-taginput:focus-within { border-color: var(--c-primary) !important; box-shadow: 0 0 0 2px rgba(124,58,237,.15) !important; }
    .wcaam-taginput-field {
        flex: 1 !important;
        min-width: 60px !important;
        width: auto !important;
        border: none !important;
        outline: none !important;
        font-size: 12px !important;
        background: transparent !important;
        padding: 2px 0 !important;
        color: var(--c-text) !important;
        writing-mode: horizontal-tb !important;
        -webkit-writing-mode: horizontal-tb !important;
        height: 20px !important;
        line-height: 20px !important;
    }
    .wcaam-tag {
        display: grid !important;
        grid-template-columns: minmax(0,1fr) 16px !important;
        align-items: center !important;
        gap: 2px !important;
        background: #ede9fe !important;
        color: #5b21b6 !important;
        border-radius: 3px !important;
        padding: 2px 4px 2px 6px !important;
        font-size: 12px !important;
        font-weight: 500 !important;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        height: auto !important;
        line-height: 1.4 !important;
    }
    .wcaam-tag .wcaam-tag-text {
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
        min-width: 0 !important;
        display: block !important;
    }
    .wcaam-tag.is-new { background: #e0f2fe !important; color: #0369a1 !important; }
    .wcaam-tag-remove {
        background: none !important;
        border: none !important;
        cursor: pointer !important;
        padding: 0 !important;
        font-size: 14px !important;
        line-height: 1 !important;
        color: inherit !important;
        opacity: .8 !important;
        width: 16px !important;
        min-width: 16px !important;
        text-align: center !important;
        writing-mode: horizontal-tb !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
    }
    .wcaam-tag-remove:hover { opacity: 1 !important; color: #dc2626 !important; }

    /* ======= TEXTAREA ATTRIBUTS TEXTE LONG ======= */
    textarea.wcaam-ta {
        width: 100% !important;
        min-width: 220px !important;
        min-height: 80px !important;
        max-height: 220px !important;
        font-size: 12px !important;
        font-family: inherit !important;
        line-height: 1.5 !important;
        border: 1px solid var(--c-border) !important;
        border-radius: var(--radius) !important;
        padding: 6px 8px !important;
        resize: vertical !important;
        box-sizing: border-box !important;
        background: #fff !important;
        color: var(--c-text) !important;
        writing-mode: horizontal-tb !important;
        -webkit-writing-mode: horizontal-tb !important;
        direction: ltr !important;
        display: block !important;
        overflow: auto !important;
        white-space: pre-wrap !important;
    }
    textarea.wcaam-ta:focus {
        outline: none !important;
        border-color: var(--c-primary) !important;
        box-shadow: 0 0 0 2px rgba(124,58,237,.15) !important;
    }

    /* ======= AUTOCOMPLETE DROPDOWN ======= */
    .wcaam-ac-dropdown {
        position:absolute; top:calc(100% + 2px); left:0; right:0; z-index:99999;
        background:#fff; border:1px solid var(--c-border); border-radius: var(--radius);
        box-shadow:0 4px 12px rgba(0,0,0,.12); max-height:180px; overflow-y:auto;
    }
    .wcaam-ac-item {
        padding:7px 10px; font-size:12px; cursor:pointer; display:flex; align-items:center; gap:6px;
    }
    .wcaam-ac-item:hover, .wcaam-ac-item.active { background:var(--c-bg); }
    .wcaam-ac-item .wcaam-ac-badge-new {
        font-size:10px; background:var(--c-new); color:#fff;
        border-radius:3px; padding:1px 5px; margin-left:auto; white-space:nowrap;
    }
    .wcaam-ac-empty { padding:8px 10px; font-size:12px; color:var(--c-muted); }

    /* ======= ÉTATS & FEEDBACK ======= */
    .wcaam-loading {
        display:flex; align-items:center; justify-content:center; gap:10px;
        padding:40px; color:var(--c-muted); font-size:14px;
    }
    .wcaam-spinner {
        width:22px; height:22px; border:3px solid var(--c-border);
        border-top-color:var(--c-primary); border-radius:50%;
        animation:wcaam-spin .7s linear infinite;
    }
    @keyframes wcaam-spin { to { transform:rotate(360deg); } }
    .wcaam-empty { padding:40px; text-align:center; color:var(--c-muted); font-size:14px; }
    .wcaam-hidden { display:none !important; }

    /* Toast notifications */
    #wcaam-toast-container {
        position:fixed; bottom:20px; right:20px; z-index:999999;
        display:flex; flex-direction:column; gap:8px;
    }
    .wcaam-toast {
        padding:10px 16px; border-radius: var(--radius); font-size:13px;
        font-weight:500; color:#fff; box-shadow:0 4px 12px rgba(0,0,0,.2);
        animation:wcaam-fadein .25s ease; max-width:360px;
    }
    .wcaam-toast.success { background:var(--c-success); }
    .wcaam-toast.error   { background:var(--c-danger); }
    .wcaam-toast.info    { background:var(--c-primary); }
    .wcaam-toast.warn    { background:var(--c-warn); }
    @keyframes wcaam-fadein { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }

    /* Indicateur de ligne en cours de sauvegarde */
    .wcaam-saving td { opacity:.6; pointer-events:none; }
    .wcaam-btn-validate { min-width:72px; }

    /* ======= PAGINATION ======= */
    .wcaam-pagination {
        display:flex; align-items:center; justify-content:flex-end; gap:6px;
        padding:10px 0; flex-wrap:wrap;
    }
    .wcaam-page-btn {
        padding:5px 10px; border:1px solid var(--c-border); border-radius: var(--radius);
        background:#fff; font-size:13px; cursor:pointer; transition:all .15s;
    }
    .wcaam-page-btn:hover  { background:var(--c-bg); }
    .wcaam-page-btn.active { background:var(--c-primary); color:#fff; border-color:var(--c-primary); font-weight:600; }
    .wcaam-page-btn:disabled { opacity:.4; cursor:not-allowed; }
    .wcaam-page-info { font-size:13px; color:var(--c-muted); }

    /* ======= MODAL ======= */
    .wcaam-modal-overlay {
        position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:999998;
        display:flex; align-items:center; justify-content:center;
    }
    .wcaam-modal {
        background:#fff; border-radius:8px; box-shadow:0 8px 32px rgba(0,0,0,.2);
        width:380px; max-width:95vw;
    }
    .wcaam-modal-header {
        display:flex; justify-content:space-between; align-items:center;
        padding:14px 18px; border-bottom:1px solid var(--c-border);
    }
    .wcaam-modal-header h3 { margin:0; font-size:15px; }
    .wcaam-modal-body   { padding:16px 18px; font-size:13px; }
    .wcaam-modal-body label { display:block; margin-bottom:6px; font-weight:500; }
    .wcaam-modal-footer { padding:12px 18px; border-top:1px solid var(--c-border); display:flex; justify-content:flex-end; gap:8px; }
    .wcaam-select-full  { width:100%; font-size:13px; padding:7px 8px; border:1px solid var(--c-border); border-radius: var(--radius); }
    </style>
    <?php
}

// 6. JS : voir fichier wcaam-main.js dans le dossier du plugin

// ============================================================
// 7. AJAX : Récupérer les produits
// ============================================================
// Attributs affichés en textarea (valeur texte long unique par produit)
function wcaam_long_text_attrs() {
    return [ 'pa_avantages', 'pa_inconvenients', 'pa_fonctionnalites', 'pa_fonctionnalites-ia' ];
}

add_action( 'wp_ajax_wcaam_get_products', 'wcaam_ajax_get_products' );
function wcaam_ajax_get_products() {
    check_ajax_referer( 'wcaam_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Accès refusé.' );

    $page     = max( 1, intval( $_POST['page']     ?? 1 ) );
    $per_page = intval( $_POST['per_page'] ?? 20 );
    $per_page = in_array( $per_page, [ 20, 50, 100 ] ) ? $per_page : 20;
    $search   = sanitize_text_field( $_POST['search'] ?? '' );

    // Colonnes demandées par le JS (on ne charge QUE ce qui est affiché)
    $cols_raw = sanitize_text_field( $_POST['cols'] ?? '' );
    $requested_cols = $cols_raw ? array_map( 'sanitize_key', explode( ',', $cols_raw ) ) : [];

    // WP_Query : tous types de produits (external, simple, etc.)
    $query_args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'no_found_rows'  => false,
        'fields'         => 'ids', // ne récupérer que les IDs = plus rapide
    ];
    if ( $search !== '' ) {
        $query_args['s'] = $search;
    }

    $query       = new WP_Query( $query_args );
    $total       = $query->found_posts;
    $pages       = $query->max_num_pages ?: 1;
    $product_ids = $query->posts; // avec fields=ids, posts = tableau d'IDs
    wp_reset_postdata();

    // Déterminer les taxonomies à charger
    $all_attribute_taxonomies = array_map( function( $a ) {
        return 'pa_' . $a->attribute_name;
    }, wc_get_attribute_taxonomies() );

    if ( ! empty( $requested_cols ) ) {
        $taxonomies_to_load = array_filter( $requested_cols, function( $col ) use ( $all_attribute_taxonomies ) {
            return $col === 'product_cat' || in_array( $col, $all_attribute_taxonomies );
        });
        $taxonomies_to_load = array_values( $taxonomies_to_load );
    } else {
        $taxonomies_to_load = array_merge( [ 'product_cat' ], $all_attribute_taxonomies );
    }

    // Construire la réponse produit par produit
    $data = [];
    foreach ( $product_ids as $pid ) {
        $pid  = intval( $pid );
        $post = get_post( $pid );
        if ( ! $post ) continue;

        $terms = [];
        // Charger _product_attributes une seule fois par produit
        $product_pa = get_post_meta( $pid, '_product_attributes', true );
        if ( ! is_array( $product_pa ) ) $product_pa = [];

        foreach ( $taxonomies_to_load as $taxonomy ) {
            $is_long        = in_array( $taxonomy, wcaam_long_text_attrs() );
            $attr_key_plain = preg_replace( '/^pa_/', '', $taxonomy );

            // Attributs texte long : lire TOUJOURS depuis _product_attributes[plain_key]
            // Ces attributs sont stockés en is_taxonomy=0 avec le texte dans 'value'
            if ( $is_long ) {
                $text_val = $product_pa[ $attr_key_plain ]['value'] ?? '';
                if ( $text_val !== '' ) {
                    $terms[ $taxonomy ] = [[
                        'slug'       => '',
                        'name'       => $text_val,
                        'isNew'      => false,
                        'isLongText' => true,
                    ]];
                } else {
                    $terms[ $taxonomy ] = [];
                }
                continue;
            }

            // Attributs normaux : lire depuis la taxonomie WC
            if ( ! taxonomy_exists( $taxonomy ) ) {
                $terms[ $taxonomy ] = [];
                continue;
            }
            $t_list = wp_get_object_terms( $pid, $taxonomy, [ 'fields' => 'all' ] );
            if ( is_wp_error( $t_list ) ) {
                $terms[ $taxonomy ] = [];
                continue;
            }
            $terms[ $taxonomy ] = array_map( function( $t ) {
                return [ 'slug' => $t->slug, 'name' => $t->name, 'isNew' => false, 'isLongText' => false ];
            }, $t_list );
        }

        // Image : utiliser directement get_post_meta pour éviter
        // d'instancier WC_Product (plus léger)
        $img    = '';
        $img_id = get_post_thumbnail_id( $pid );
        if ( $img_id ) {
            $img_src = wp_get_attachment_image_src( $img_id, 'thumbnail' );
            if ( $img_src ) $img = $img_src[0];
        }

        $data[] = [
            'id'    => $pid,
            'name'  => get_the_title( $pid ),
            'sku'   => get_post_meta( $pid, '_sku', true ),
            'image' => $img,
            'terms' => $terms,
        ];
    }

    wp_send_json_success([
        'products'    => $data,
        'total'       => (int) $total,
        'total_pages' => (int) $pages,
    ]);
}

// ============================================================
// 8. AJAX : Suggestions d'autocomplétion
// ============================================================
add_action( 'wp_ajax_wcaam_get_term_suggestions', 'wcaam_ajax_get_term_suggestions' );
function wcaam_ajax_get_term_suggestions() {
    check_ajax_referer( 'wcaam_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Accès refusé.' );

    $taxonomy = sanitize_key( $_POST['taxonomy'] ?? '' );
    $query    = sanitize_text_field( $_POST['q'] ?? '' );

    if ( ! taxonomy_exists( $taxonomy ) ) {
        wp_send_json_success([ 'terms' => [] ]);
    }

    global $wpdb;

    // Recherche SQL directe : insensible à la casse ET aux accents (utf8mb4_unicode_ci)
    // On filtre les termes dont le nom > 120 chars (ce sont des listes stockées comme
    // un seul terme, pas des valeurs unitaires utilisables)
    $max_name_len = 120;

    if ( $query !== '' ) {
        $like = '%' . $wpdb->esc_like( $query ) . '%';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.term_id, t.slug, t.name, tt.count
             FROM {$wpdb->terms} t
             JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
             WHERE tt.taxonomy = %s
               AND CHAR_LENGTH(t.name) <= %d
               AND t.name LIKE %s COLLATE utf8mb4_unicode_ci
             ORDER BY tt.count DESC, t.name ASC
             LIMIT 200",
            $taxonomy, $max_name_len, $like
        ) );
    } else {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.term_id, t.slug, t.name, tt.count
             FROM {$wpdb->terms} t
             JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
             WHERE tt.taxonomy = %s
               AND CHAR_LENGTH(t.name) <= %d
             ORDER BY tt.count DESC, t.name ASC
             LIMIT 200",
            $taxonomy, $max_name_len
        ) );
    }

    // Déduplication insensible à la casse et aux accents côté PHP
    // Normaliser : minuscules + suppression accents pour comparaison
    $seen   = [];
    $result = [];

    foreach ( (array) $rows as $row ) {
        // Clé de déduplication : lowercase + translitération basique
        $key = mb_strtolower( $row->name, 'UTF-8' );
        $key = strtr( $key, [
            'à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ã'=>'a',
            'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
            'î'=>'i','ï'=>'i','í'=>'i','ì'=>'i',
            'ô'=>'o','ö'=>'o','ó'=>'o','ò'=>'o','õ'=>'o',
            'ù'=>'u','û'=>'u','ü'=>'u','ú'=>'u',
            'ç'=>'c','ñ'=>'n',
        ] );

        if ( isset( $seen[ $key ] ) ) continue; // doublon → ignorer
        $seen[ $key ] = true;

        $result[] = [ 'slug' => $row->slug, 'name' => $row->name ];
    }

    wp_send_json_success([ 'terms' => $result ]);
}

// ============================================================
// 9. AJAX : Sauvegarder un produit
// ============================================================
add_action( 'wp_ajax_wcaam_save_product', 'wcaam_ajax_save_product' );
function wcaam_ajax_save_product() {
    check_ajax_referer( 'wcaam_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Accès refusé.' );

    $product_id = intval( $_POST['product_id'] ?? 0 );
    $terms_raw  = sanitize_text_field( $_POST['terms'] ?? '' );

    if ( ! $product_id ) wp_send_json_error( 'ID produit invalide.' );

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        $post = get_post( $product_id );
        if ( ! $post || $post->post_type !== 'product' ) {
            wp_send_json_error( 'Produit introuvable.' );
        }
        if ( class_exists( 'WC_Product_External' ) ) {
            $product = new WC_Product_External( $product_id );
        } else {
            $product = new WC_Product( $product_id );
        }
    }

    $terms_data    = json_decode( wp_unslash( $terms_raw ), true );
    if ( ! is_array( $terms_data ) ) wp_send_json_error( 'Données invalides.' );

    $created_terms = [];

    foreach ( $terms_data as $taxonomy => $term_list ) {
        // Validation taxonomy
        $taxonomy = sanitize_key( $taxonomy );
        if ( ! taxonomy_exists( $taxonomy ) && $taxonomy !== 'product_cat' ) continue;

        $term_ids = [];

        foreach ( $term_list as $term_info ) {
            $term_name = sanitize_text_field( $term_info['name'] ?? '' );
            $is_new    = ! empty( $term_info['isNew'] );

            if ( $term_name === '' ) continue;

            // Normalisation casse : première lettre majuscule
            $term_name = mb_strtoupper( mb_substr( $term_name, 0, 1 ) ) . mb_substr( $term_name, 1 );

            // Vérifier si le terme existe (insensible à la casse)
            $existing = term_exists( $term_name, $taxonomy );

            // Double vérification en lowercase pour éviter les doublons
            if ( ! $existing ) {
                $existing = get_terms([
                    'taxonomy'   => $taxonomy,
                    'name'       => $term_name,
                    'hide_empty' => false,
                    'number'     => 1,
                ]);
                if ( ! is_wp_error( $existing ) && ! empty( $existing ) ) {
                    $existing = [ 'term_id' => $existing[0]->term_id ];
                } else {
                    $existing = null;
                }
            }

            if ( $existing ) {
                $term_id = is_array( $existing ) ? $existing['term_id'] : $existing;
            } else {
                // Créer le terme
                $new_term = wp_insert_term( $term_name, $taxonomy );
                if ( is_wp_error( $new_term ) ) {
                    // Si le terme existe déjà (race condition)
                    if ( $new_term->get_error_code() === 'term_exists' ) {
                        $term_id = $new_term->get_error_data();
                    } else {
                        // Autre erreur : on l'ignore et on continue
                        continue;
                    }
                } else {
                    $term_id = $new_term['term_id'];
                    $created_terms[] = [
                        'taxonomy' => $taxonomy,
                        'name'     => $term_name,
                    ];
                }
            }

            if ( $term_id ) {
                $term_ids[] = intval( $term_id );
            }
        }

        // Assigner les termes au produit (remplace les anciens)
        $set_result = wp_set_object_terms( $product_id, $term_ids, $taxonomy );
        if ( is_wp_error( $set_result ) ) {
            wp_send_json_error( 'Erreur lors de l\'assignation des termes : ' . $set_result->get_message() );
        }
    }

    // Si des attributs globaux (pa_*) ont été mis à jour, synchroniser les attributs du produit WooCommerce
    $product_attributes = [];
    foreach ( $terms_data as $taxonomy => $term_list ) {
        if ( strpos( $taxonomy, 'pa_' ) !== 0 ) continue;
        if ( ! taxonomy_exists( $taxonomy ) ) continue;

        $attr_name = str_replace( 'pa_', '', $taxonomy );
        $term_names = array_map( function($t) {
            $n = sanitize_text_field($t['name'] ?? '');
            return mb_strtoupper( mb_substr($n,0,1) ) . mb_substr($n,1);
        }, $term_list );
        $term_names = array_filter( $term_names );

        // Récupérer les IDs des termes assignés
        $assigned = wp_get_object_terms( $product_id, $taxonomy, ['fields' => 'ids'] );
        if ( is_wp_error( $assigned ) ) $assigned = [];

        $product_attributes[ $taxonomy ] = [
            'name'         => $taxonomy,
            'value'        => '',
            'position'     => 0,
            'is_visible'   => 1,
            'is_variation' => 0,
            'is_taxonomy'  => 1,
        ];
    }

    if ( ! empty( $product_attributes ) ) {
        // Fusionner avec les attributs existants
        $existing_attrs = $product->get_attributes();
        $wc_attributes  = [];

        foreach ( $existing_attrs as $key => $attr ) {
            $wc_attributes[ $key ] = $attr;
        }

        foreach ( array_keys( $product_attributes ) as $taxonomy ) {
            // Créer ou mettre à jour l'attribut WC
            $wc_attr = new WC_Product_Attribute();
            $wc_attr->set_name( $taxonomy );
            $wc_attr->set_id( wc_attribute_taxonomy_id_by_name( str_replace( 'pa_', '', $taxonomy ) ) );

            $term_ids_for_attr = wp_get_object_terms( $product_id, $taxonomy, ['fields' => 'ids'] );
            if ( is_wp_error( $term_ids_for_attr ) ) $term_ids_for_attr = [];

            $wc_attr->set_options( $term_ids_for_attr );
            $wc_attr->set_visible( true );
            $wc_attr->set_variation( false );
            $wc_attr->set_position( isset( $wc_attributes[ $taxonomy ] ) ? $wc_attributes[ $taxonomy ]->get_position() : 0 );

            $wc_attributes[ $taxonomy ] = $wc_attr;
        }

        $product->set_attributes( $wc_attributes );
        $product->save();
    }

    // Vider les caches WooCommerce et object cache
    wc_delete_product_transients( $product_id );
    clean_term_cache( array_map( 'intval', array_keys( $terms_data ) ), '', false );

    wp_send_json_success([
        'message'       => 'Produit mis à jour.',
        'created_terms' => array_map( function($t){ return $t['name']; }, $created_terms ),
    ]);
}

// ============================================================
// 10. AJAX : Application en masse
// ============================================================
add_action( 'wp_ajax_wcaam_bulk_apply', 'wcaam_ajax_bulk_apply' );
function wcaam_ajax_bulk_apply() {
    check_ajax_referer( 'wcaam_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Accès refusé.' );

    $product_ids_raw = sanitize_text_field( $_POST['product_ids'] ?? '' );
    $terms_raw       = sanitize_text_field( $_POST['terms']       ?? '' );

    $product_ids = json_decode( wp_unslash( $product_ids_raw ), true );
    $terms_data  = json_decode( wp_unslash( $terms_raw ), true );

    if ( ! is_array( $product_ids ) || ! is_array( $terms_data ) ) {
        wp_send_json_error( 'Données invalides.' );
    }

    $product_ids   = array_map( 'intval', $product_ids );
    $product_ids   = array_filter( $product_ids );
    $updated       = 0;
    $all_created   = [];

    foreach ( $product_ids as $product_id ) {
        // Réutiliser la logique de save en injectant les données dans $_POST
        // On appelle directement la fonction de traitement
        $result = wcaam_process_save( $product_id, $terms_data );
        if ( ! is_wp_error( $result ) ) {
            $updated++;
            if ( ! empty( $result['created_terms'] ) ) {
                $all_created = array_merge( $all_created, $result['created_terms'] );
            }
        }
    }

    // Dédupliquer les termes créés
    $all_created = array_unique( $all_created );

    wp_send_json_success([
        'updated'       => $updated,
        'created_terms' => $all_created,
    ]);
}

/**
 * Fonction de traitement de la sauvegarde (utilisée par save et bulk).
 *
 * @param int   $product_id
 * @param array $terms_data  [ taxonomy => [ ['name'=>..., 'isNew'=>...], ... ], ... ]
 * @return array|WP_Error
 */
function wcaam_process_save( int $product_id, array $terms_data ) {
    $product = wc_get_product( $product_id );
    // Fallback pour produits external / types personnalisés
    if ( ! $product ) {
        $post = get_post( $product_id );
        if ( ! $post || $post->post_type !== 'product' ) {
            return new WP_Error( 'not_found', 'Produit introuvable.' );
        }
        if ( class_exists( 'WC_Product_External' ) ) {
            $product = new WC_Product_External( $product_id );
        } else {
            $product = new WC_Product( $product_id );
        }
    }

    $created_terms = [];

    // (pas d'attributs texte libre — tout passe par les taxonomies WC)

    foreach ( $terms_data as $taxonomy => $term_list ) {
        $taxonomy = sanitize_key( $taxonomy );

        // ── Attributs texte long : stockage en is_taxonomy=0 dans _product_attributes ──
        // EXACTEMENT comme la structure REHub originale (comme AB Tasty, etc.)
        // NE PAS créer de termes WC pour ces attributs
        if ( in_array( $taxonomy, wcaam_long_text_attrs() ) ) {
            if ( empty( $term_list ) ) continue;

            $raw_text  = trim( $term_list[0]['name'] ?? '' );
            $plain_key = preg_replace( '/^pa_/', '', $taxonomy );

            // Charger _product_attributes
            $pa_current = get_post_meta( $product_id, '_product_attributes', true );
            if ( ! is_array( $pa_current ) ) $pa_current = [];

            if ( $raw_text === '' ) {
                // Texte vide : supprimer la valeur mais garder la structure
                if ( isset( $pa_current[ $plain_key ] ) ) {
                    $pa_current[ $plain_key ]['value'] = '';
                    update_post_meta( $product_id, '_product_attributes', $pa_current );
                }
                continue;
            }

            // Mettre à jour ou créer l'entrée texte libre
            if ( ! isset( $pa_current[ $plain_key ] ) ) {
                $pa_current[ $plain_key ] = [
                    'name'         => ucfirst( str_replace( '-', ' ', $plain_key ) ),
                    'value'        => $raw_text,
                    'position'     => 0,
                    'is_visible'   => 1,
                    'is_variation' => 0,
                    'is_taxonomy'  => 0,
                ];
            } else {
                $pa_current[ $plain_key ]['value']       = $raw_text;
                $pa_current[ $plain_key ]['is_taxonomy'] = 0;
            }

            update_post_meta( $product_id, '_product_attributes', $pa_current );
            continue;
        }

        if ( ! taxonomy_exists( $taxonomy ) && $taxonomy !== 'product_cat' ) continue;

        $term_ids = [];

        foreach ( $term_list as $term_info ) {
            $term_name = sanitize_text_field( $term_info['name'] ?? '' );
            if ( $term_name === '' ) continue;

            // Casse : première lettre majuscule
            $term_name = mb_strtoupper( mb_substr( $term_name, 0, 1 ) ) . mb_substr( $term_name, 1 );

            $existing = term_exists( $term_name, $taxonomy );
            if ( ! $existing ) {
                $found = get_terms([
                    'taxonomy'   => $taxonomy,
                    'name'       => $term_name,
                    'hide_empty' => false,
                    'number'     => 1,
                ]);
                if ( ! is_wp_error( $found ) && ! empty( $found ) ) {
                    $existing = [ 'term_id' => $found[0]->term_id ];
                }
            }

            if ( $existing ) {
                $term_id = is_array( $existing ) ? $existing['term_id'] : $existing;
            } else {
                $new_term = wp_insert_term( $term_name, $taxonomy );
                if ( is_wp_error( $new_term ) ) {
                    if ( $new_term->get_error_code() === 'term_exists' ) {
                        $term_id = $new_term->get_error_data();
                    } else {
                        continue;
                    }
                } else {
                    $term_id = $new_term['term_id'];
                    $created_terms[] = $term_name;
                }
            }

            if ( $term_id ) $term_ids[] = intval( $term_id );
        }

        wp_set_object_terms( $product_id, $term_ids, $taxonomy );
    }



    // Synchronisation attributs WC (pa_*)
    $existing_attrs = $product->get_attributes();
    $wc_attributes  = [];
    foreach ( $existing_attrs as $key => $attr ) {
        $wc_attributes[ $key ] = $attr;
    }

    foreach ( array_keys( $terms_data ) as $taxonomy ) {
        if ( strpos( $taxonomy, 'pa_' ) !== 0 ) continue;
        if ( ! taxonomy_exists( $taxonomy ) ) continue;

        $wc_attr = new WC_Product_Attribute();
        $wc_attr->set_name( $taxonomy );
        $wc_attr->set_id( wc_attribute_taxonomy_id_by_name( str_replace( 'pa_', '', $taxonomy ) ) );

        $term_ids_for_attr = wp_get_object_terms( $product_id, $taxonomy, ['fields' => 'ids'] );
        if ( is_wp_error( $term_ids_for_attr ) ) $term_ids_for_attr = [];

        $wc_attr->set_options( $term_ids_for_attr );
        $wc_attr->set_visible( true );
        $wc_attr->set_variation( false );
        $wc_attr->set_position( isset( $wc_attributes[ $taxonomy ] ) ? $wc_attributes[ $taxonomy ]->get_position() : 0 );

        $wc_attributes[ $taxonomy ] = $wc_attr;
    }

    $product->set_attributes( $wc_attributes );
    $product->save();
    wc_delete_product_transients( $product_id );

    return [ 'created_terms' => $created_terms ];
}

// ============================================================
// 11. AJAX : Récupérer la liste des attributs globaux
//     (utilisé si besoin par le JS pour rafraîchir dynamiquement)
// ============================================================
add_action( 'wp_ajax_wcaam_get_attributes', 'wcaam_ajax_get_attributes' );
function wcaam_ajax_get_attributes() {
    check_ajax_referer( 'wcaam_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Accès refusé.' );

    $attrs = wc_get_attribute_taxonomies();
    $result = array_values( array_map( function( $a ) {
        return [ 'taxonomy' => 'pa_' . $a->attribute_name, 'label' => $a->attribute_label ];
    }, $attrs ) );

    wp_send_json_success([ 'attributes' => $result ]);
}
