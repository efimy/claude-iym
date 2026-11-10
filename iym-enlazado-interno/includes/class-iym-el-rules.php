<?php
/**
 * Almacenamiento de reglas y ajustes.
 *
 * @package IYM_Enlazado_Interno
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD de reglas guardadas en la opción `iym_el_rules`.
 */
class IYM_EL_Rules {

	const OPTION   = 'iym_el_rules';
	const NEXT_ID  = 'iym_el_next_id';
	const SETTINGS = 'iym_el_settings';
	const VERSION  = 'iym_el_rules_version';

	/**
	 * Ajustes por defecto.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'post_types'      => array( 'post', 'page' ),
			'max_per_post'    => 10,
			'max_per_url'     => 1,
			'skip_linked_url' => 1,
			'exclude_ids'     => array(),
			'link_class'      => 'iym-el-link',
		);
	}

	/**
	 * Ajustes actuales combinados con los valores por defecto.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::SETTINGS, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::default_settings() );
	}

	/**
	 * Todas las reglas, indexadas por ID.
	 *
	 * @return array
	 */
	public static function all() {
		$rules = get_option( self::OPTION, array() );
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Reglas activas.
	 *
	 * @return array
	 */
	public static function active() {
		return array_filter(
			self::all(),
			static function ( $rule ) {
				return ! empty( $rule['active'] ) && ! empty( $rule['keywords'] ) && ! empty( $rule['url'] );
			}
		);
	}

	/**
	 * Una regla por ID.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$rules = self::all();
		return isset( $rules[ $id ] ) ? $rules[ $id ] : null;
	}

	/**
	 * Normaliza y valida los datos de una regla.
	 *
	 * @param array $data Datos sin procesar.
	 * @return array|WP_Error
	 */
	public static function sanitize( $data ) {
		$keywords = isset( $data['keywords'] ) ? $data['keywords'] : '';
		if ( ! is_array( $keywords ) ) {
			$keywords = preg_split( '/[,\n\r]+/u', (string) $keywords );
		}
		$keywords = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $k ) {
							return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $k ) ) );
						},
						$keywords
					),
					'strlen'
				)
			)
		);

		if ( empty( $keywords ) ) {
			return new WP_Error( 'iym_el_keywords', __( 'Añade al menos una palabra clave.', 'iym-enlazado-interno' ) );
		}

		$url = isset( $data['url'] ) ? trim( (string) $data['url'] ) : '';
		if ( '' !== $url && '/' === $url[0] && ( ! isset( $url[1] ) || '/' !== $url[1] ) ) {
			$url = home_url( $url );
		}
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return new WP_Error( 'iym_el_url', __( 'La URL de destino no es válida.', 'iym-enlazado-interno' ) );
		}

		return array(
			'keywords'       => $keywords,
			'url'            => $url,
			'target_id'      => (int) url_to_postid( $url ),
			'max_per_post'   => max( 1, (int) ( isset( $data['max_per_post'] ) ? $data['max_per_post'] : 1 ) ),
			'case_sensitive' => empty( $data['case_sensitive'] ) ? 0 : 1,
			'nofollow'       => empty( $data['nofollow'] ) ? 0 : 1,
			'new_tab'        => empty( $data['new_tab'] ) ? 0 : 1,
			'active'         => empty( $data['active'] ) ? 0 : 1,
		);
	}

	/**
	 * Crea o actualiza una regla.
	 *
	 * @param array $data Datos.
	 * @param int   $id   ID existente (0 para crear).
	 * @return int|WP_Error ID de la regla.
	 */
	public static function save( $data, $id = 0 ) {
		$rule = self::sanitize( $data );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}

		$rules = self::all();
		$id    = (int) $id;
		if ( $id <= 0 || ! isset( $rules[ $id ] ) ) {
			$id = (int) get_option( self::NEXT_ID, 1 );
			update_option( self::NEXT_ID, $id + 1, false );
		}

		$rule['id']    = $id;
		$rules[ $id ] = $rule;
		self::store( $rules );

		return $id;
	}

	/**
	 * Borra una regla.
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		$rules = self::all();
		if ( ! isset( $rules[ $id ] ) ) {
			return false;
		}
		unset( $rules[ $id ] );
		self::store( $rules );
		return true;
	}

	/**
	 * Activa o desactiva una regla.
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public static function toggle( $id ) {
		$rules = self::all();
		if ( ! isset( $rules[ $id ] ) ) {
			return false;
		}
		$rules[ $id ]['active'] = empty( $rules[ $id ]['active'] ) ? 1 : 0;
		self::store( $rules );
		return true;
	}

	/**
	 * Guarda la lista completa e invalida la caché.
	 *
	 * @param array $rules Reglas.
	 */
	public static function store( $rules ) {
		update_option( self::OPTION, $rules, false );
		self::bump_version();
	}

	/**
	 * Cambia la versión para invalidar la caché de contenido enlazado.
	 */
	public static function bump_version() {
		update_option( self::VERSION, (string) microtime( true ), true );
	}

	/**
	 * Versión actual de reglas y ajustes.
	 *
	 * @return string
	 */
	public static function version() {
		return (string) get_option( self::VERSION, '0' );
	}
}
