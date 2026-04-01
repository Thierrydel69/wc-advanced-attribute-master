<?php
/**
 * Plugin Name: WCAAM REHub Attribute Groups Fix
 * Description: Corrige l'affichage des 4 attributs texte libre dans les REHub Attribute Groups. Pérenne : fonctionne après chaque mise à jour REHub.
 * Version:     1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BUG REHub (helper-functions.php, rh_get_attributes_group, ligne 762) :
 *
 *   $attribute_in_group = wc_get_attribute( $id );  // retourne slug = 'pa_avantages'
 *   if ( $attribute_in_group->slug == $attribute['name'] ) { ... }
 *
 * Pour les attributs texte libre (is_taxonomy=0) :
 *   → slug    = 'pa_avantages'
 *   → name    = 'Avantages'       ← jamais égaux → tombe dans "Specifications"
 *
 * FIX : via woocommerce_product_get_attributes (filtre WC natif et stable),
 * on remplace le name 'Avantages' par 'pa_avantages' UNIQUEMENT sur le front-end.
 * REHub trouve alors 'pa_avantages' == 'pa_avantages' → attribut dans le bon groupe.
 *
 * Ce filtre ne modifie RIEN en base de données.
 * Il ne touche PAS à woocommerce_display_product_attributes (qui causait des crashes).
 * Il résiste aux mises à jour REHub car il s'appuie sur un hook WooCommerce natif.
 */
add_filter( 'woocommerce_product_get_attributes', 'wcaamfix_text_free_attrs', 10, 2 );

function wcaamfix_text_free_attrs( $attributes, $product ) {
    // Front-end uniquement
    if ( is_admin() ) return $attributes;
    if ( ! is_array( $attributes ) || empty( $attributes ) ) return $attributes;

    // Mapping clé texte libre → slug WC attendu par REHub
    $fix_map = [
        'avantages'          => 'pa_avantages',
        'inconvenients'      => 'pa_inconvenients',
        'fonctionnalites'    => 'pa_fonctionnalites',
        'fonctionnalites-ia' => 'pa_fonctionnalites-ia',
    ];

    foreach ( $fix_map as $plain_key => $pa_slug ) {
        if ( ! isset( $attributes[ $plain_key ] ) ) continue;

        $attr = $attributes[ $plain_key ];

        // Modifier le name via réflexion PHP (WC_Product_Attribute a $data privé)
        if ( $attr instanceof WC_Product_Attribute ) {
            try {
                $clone      = clone $attr;
                $reflection = new ReflectionClass( $clone );
                $data_prop  = $reflection->getProperty( 'data' );
                $data_prop->setAccessible( true );
                $data         = $data_prop->getValue( $clone );
                $data['name'] = $pa_slug;
                $data_prop->setValue( $clone, $data );

                unset( $attributes[ $plain_key ] );
                $attributes[ $pa_slug ] = $clone;

            } catch ( Exception $e ) {
                // Réflexion impossible : laisser intact
            }
        } elseif ( is_array( $attr ) ) {
            $attr['name'] = $pa_slug;
            unset( $attributes[ $plain_key ] );
            $attributes[ $pa_slug ] = $attr;
        }
    }

    return $attributes;
}
