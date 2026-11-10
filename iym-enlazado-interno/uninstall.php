<?php
/**
 * Limpieza al desinstalar.
 *
 * @package IYM_Enlazado_Interno
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'iym_el_rules', 'iym_el_next_id', 'iym_el_settings', 'iym_el_rules_version' ) as $iym_el_option ) {
	delete_option( $iym_el_option );
}
delete_post_meta_by_key( '_iym_el_disable' );
