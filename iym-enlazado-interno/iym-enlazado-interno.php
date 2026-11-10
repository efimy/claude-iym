<?php
/**
 * Plugin Name:       IYM Enlazado Interno
 * Description:       Enlazado interno automático por palabras clave. Inserta los enlaces al mostrar el contenido, sin modificar la base de datos.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            IYM
 * License:           GPL-2.0-or-later
 * Text Domain:       iym-enlazado-interno
 *
 * @package IYM_Enlazado_Interno
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IYM_EL_VERSION', '0.1.0' );
define( 'IYM_EL_FILE', __FILE__ );
define( 'IYM_EL_DIR', plugin_dir_path( __FILE__ ) );
define( 'IYM_EL_URL', plugin_dir_url( __FILE__ ) );

require_once IYM_EL_DIR . 'includes/class-iym-el-rules.php';
require_once IYM_EL_DIR . 'includes/class-iym-el-linker.php';

if ( is_admin() ) {
	require_once IYM_EL_DIR . 'includes/class-iym-el-admin.php';
}

/**
 * Arranque del plugin.
 */
function iym_el_init() {
	IYM_EL_Linker::instance();
	if ( is_admin() ) {
		IYM_EL_Admin::instance();
	}
}
add_action( 'plugins_loaded', 'iym_el_init' );

/**
 * Valores por defecto al activar.
 */
function iym_el_activate() {
	if ( false === get_option( 'iym_el_settings' ) ) {
		add_option( 'iym_el_settings', IYM_EL_Rules::default_settings() );
	}
	if ( false === get_option( 'iym_el_rules' ) ) {
		add_option( 'iym_el_rules', array(), '', false );
	}
	if ( false === get_option( 'iym_el_next_id' ) ) {
		add_option( 'iym_el_next_id', 1, '', false );
	}
}
register_activation_hook( __FILE__, 'iym_el_activate' );
