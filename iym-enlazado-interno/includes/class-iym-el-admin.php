<?php
/**
 * Pantallas de administración.
 *
 * @package IYM_Enlazado_Interno
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menú, reglas, ajustes, importación/exportación y meta box.
 */
class IYM_EL_Admin {

	const SLUG = 'iym-enlazado-interno';
	const CAP  = 'manage_options';

	/**
	 * Instancia única.
	 *
	 * @var IYM_EL_Admin|null
	 */
	protected static $instance = null;

	/**
	 * Devuelve la instancia.
	 *
	 * @return IYM_EL_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	/**
	 * Registra los hooks.
	 */
	protected function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_iym_el_save_rule', array( $this, 'handle_save_rule' ) );
		add_action( 'admin_post_iym_el_delete_rule', array( $this, 'handle_delete_rule' ) );
		add_action( 'admin_post_iym_el_toggle_rule', array( $this, 'handle_toggle_rule' ) );
		add_action( 'admin_post_iym_el_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_iym_el_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_iym_el_import', array( $this, 'handle_import' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( IYM_EL_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Menú de administración.
	 */
	public function menu() {
		add_menu_page(
			__( 'Enlazado interno', 'iym-enlazado-interno' ),
			__( 'Enlazado interno', 'iym-enlazado-interno' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render_page' ),
			'dashicons-admin-links',
			81
		);
	}

	/**
	 * CSS del panel.
	 *
	 * @param string $hook Pantalla.
	 */
	public function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG === $hook ) {
			wp_enqueue_style( 'iym-el-admin', IYM_EL_URL . 'assets/admin.css', array(), IYM_EL_VERSION );
		}
	}

	/**
	 * Enlace a «Ajustes» en la lista de plugins.
	 *
	 * @param array $links Enlaces.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( $this->url() ) . '">' . esc_html__( 'Reglas', 'iym-enlazado-interno' ) . '</a>' );
		return $links;
	}

	/**
	 * URL del panel.
	 *
	 * @param array $args Parámetros.
	 * @return string
	 */
	protected function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Redirige con un aviso.
	 *
	 * @param array $args Parámetros.
	 */
	protected function redirect( $args ) {
		wp_safe_redirect( $this->url( $args ) );
		exit;
	}

	/**
	 * Comprueba permisos y nonce.
	 *
	 * @param string $action Acción del nonce.
	 */
	protected function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes.', 'iym-enlazado-interno' ), 403 );
		}
		check_admin_referer( $action );
	}

	/* ---------------------------------------------------------------------
	 * Pantalla principal
	 * ------------------------------------------------------------------- */

	/**
	 * Renderiza el panel con pestañas.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'rules';
		$tabs = array(
			'rules'    => __( 'Reglas', 'iym-enlazado-interno' ),
			'settings' => __( 'Ajustes', 'iym-enlazado-interno' ),
			'tools'    => __( 'Importar / Exportar', 'iym-enlazado-interno' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'rules';
		}
		?>
		<div class="wrap iym-el">
			<h1><?php esc_html_e( 'Enlazado interno', 'iym-enlazado-interno' ); ?></h1>
			<?php $this->notices(); ?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( $this->url( array( 'tab' => $key ) ) ); ?>" class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="iym-el-body">
				<?php
				if ( 'settings' === $tab ) {
					$this->render_settings();
				} elseif ( 'tools' === $tab ) {
					$this->render_tools();
				} else {
					$this->render_rules();
				}
				?>
			</div>
		</div>
		<?php
		// phpcs:enable
	}

	/**
	 * Avisos tras una acción.
	 */
	protected function notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'saved'    => array( 'success', __( 'Regla guardada.', 'iym-enlazado-interno' ) ),
			'deleted'  => array( 'success', __( 'Regla eliminada.', 'iym-enlazado-interno' ) ),
			'toggled'  => array( 'success', __( 'Estado de la regla actualizado.', 'iym-enlazado-interno' ) ),
			'settings' => array( 'success', __( 'Ajustes guardados.', 'iym-enlazado-interno' ) ),
		);
		if ( isset( $_GET['msg'] ) ) {
			$msg = sanitize_key( wp_unslash( $_GET['msg'] ) );
			if ( isset( $messages[ $msg ] ) ) {
				printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $msg ][0] ), esc_html( $messages[ $msg ][1] ) );
			} elseif ( 'imported' === $msg ) {
				$n = isset( $_GET['n'] ) ? absint( $_GET['n'] ) : 0;
				$e = isset( $_GET['e'] ) ? absint( $_GET['e'] ) : 0;
				/* translators: 1: reglas importadas, 2: filas con error. */
				printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( __( 'Importadas %1$d reglas. Filas omitidas: %2$d.', 'iym-enlazado-interno' ), $n, $e ) ) );
			}
		}
		if ( isset( $_GET['error'] ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ) );
		}
		// phpcs:enable
	}

	/**
	 * Pestaña de reglas: formulario + tabla.
	 */
	protected function render_rules() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable
		$editing = $edit_id ? IYM_EL_Rules::get( $edit_id ) : null;
		$rule    = wp_parse_args(
			$editing ? $editing : array(),
			array(
				'id'             => 0,
				'keywords'       => array(),
				'url'            => '',
				'max_per_post'   => 1,
				'case_sensitive' => 0,
				'nofollow'       => 0,
				'new_tab'        => 0,
				'active'         => 1,
			)
		);
		$rules   = IYM_EL_Rules::all();
		if ( '' !== $search ) {
			$needle = mb_strtolower( $search );
			$rules  = array_filter(
				$rules,
				static function ( $r ) use ( $needle ) {
					return false !== mb_strpos( mb_strtolower( implode( ', ', $r['keywords'] ) . ' ' . $r['url'] ), $needle );
				}
			);
		}
		krsort( $rules );
		?>
		<div class="iym-el-grid">
			<div class="iym-el-card">
				<h2><?php echo $editing ? esc_html__( 'Editar regla', 'iym-enlazado-interno' ) : esc_html__( 'Nueva regla', 'iym-enlazado-interno' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="iym_el_save_rule">
					<input type="hidden" name="id" value="<?php echo esc_attr( $rule['id'] ); ?>">
					<?php wp_nonce_field( 'iym_el_save_rule' ); ?>
					<p>
						<label for="iym-el-keywords"><strong><?php esc_html_e( 'Palabras clave', 'iym-enlazado-interno' ); ?></strong></label>
						<textarea id="iym-el-keywords" name="keywords" rows="4" class="large-text" required placeholder="<?php esc_attr_e( 'Una por línea o separadas por comas', 'iym-enlazado-interno' ); ?>"><?php echo esc_textarea( implode( "\n", $rule['keywords'] ) ); ?></textarea>
					</p>
					<p>
						<label for="iym-el-url"><strong><?php esc_html_e( 'URL de destino', 'iym-enlazado-interno' ); ?></strong></label>
						<input id="iym-el-url" type="text" name="url" class="large-text" required value="<?php echo esc_attr( $rule['url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/mi-pagina/' ) ); ?>">
						<span class="description"><?php esc_html_e( 'Si apunta a una entrada de este sitio, el enlace sigue funcionando aunque cambie el slug.', 'iym-enlazado-interno' ); ?></span>
					</p>
					<p>
						<label for="iym-el-max"><?php esc_html_e( 'Máximo de enlaces por entrada', 'iym-enlazado-interno' ); ?></label>
						<input id="iym-el-max" type="number" min="1" max="50" name="max_per_post" class="small-text" value="<?php echo esc_attr( $rule['max_per_post'] ); ?>">
					</p>
					<fieldset class="iym-el-checks">
						<label><input type="checkbox" name="active" value="1" <?php checked( $rule['active'] ); ?>> <?php esc_html_e( 'Activa', 'iym-enlazado-interno' ); ?></label>
						<label><input type="checkbox" name="case_sensitive" value="1" <?php checked( $rule['case_sensitive'] ); ?>> <?php esc_html_e( 'Distinguir mayúsculas', 'iym-enlazado-interno' ); ?></label>
						<label><input type="checkbox" name="nofollow" value="1" <?php checked( $rule['nofollow'] ); ?>> nofollow</label>
						<label><input type="checkbox" name="new_tab" value="1" <?php checked( $rule['new_tab'] ); ?>> <?php esc_html_e( 'Abrir en pestaña nueva', 'iym-enlazado-interno' ); ?></label>
					</fieldset>
					<p class="submit">
						<?php submit_button( $editing ? __( 'Actualizar regla', 'iym-enlazado-interno' ) : __( 'Añadir regla', 'iym-enlazado-interno' ), 'primary', 'submit', false ); ?>
						<?php if ( $editing ) : ?>
							<a class="button" href="<?php echo esc_url( $this->url() ); ?>"><?php esc_html_e( 'Cancelar', 'iym-enlazado-interno' ); ?></a>
						<?php endif; ?>
					</p>
				</form>
			</div>

			<div class="iym-el-card iym-el-list">
				<form method="get" class="iym-el-search">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Buscar palabra clave o URL', 'iym-enlazado-interno' ); ?>">
					<?php submit_button( __( 'Buscar', 'iym-enlazado-interno' ), 'secondary', '', false ); ?>
				</form>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Palabras clave', 'iym-enlazado-interno' ); ?></th>
							<th><?php esc_html_e( 'Destino', 'iym-enlazado-interno' ); ?></th>
							<th><?php esc_html_e( 'Máx.', 'iym-enlazado-interno' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'iym-enlazado-interno' ); ?></th>
							<th><?php esc_html_e( 'Acciones', 'iym-enlazado-interno' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php if ( empty( $rules ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'Todavía no hay reglas.', 'iym-enlazado-interno' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rules as $r ) : ?>
						<?php
						$toggle = wp_nonce_url( admin_url( 'admin-post.php?action=iym_el_toggle_rule&id=' . $r['id'] ), 'iym_el_toggle_rule_' . $r['id'] );
						$delete = wp_nonce_url( admin_url( 'admin-post.php?action=iym_el_delete_rule&id=' . $r['id'] ), 'iym_el_delete_rule_' . $r['id'] );
						$title  = ! empty( $r['target_id'] ) ? get_the_title( $r['target_id'] ) : '';
						?>
						<tr class="<?php echo empty( $r['active'] ) ? 'iym-el-inactive' : ''; ?>">
							<td>
								<?php foreach ( $r['keywords'] as $kw ) : ?>
									<span class="iym-el-kw"><?php echo esc_html( $kw ); ?></span>
								<?php endforeach; ?>
								<?php if ( ! empty( $r['case_sensitive'] ) ) : ?>
									<span class="iym-el-flag">Aa</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $title ) : ?>
									<strong><?php echo esc_html( $title ); ?></strong><br>
								<?php endif; ?>
								<a href="<?php echo esc_url( $r['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $r['url'] ); ?></a>
								<?php if ( empty( $r['target_id'] ) ) : ?>
									<span class="iym-el-flag"><?php esc_html_e( 'externa', 'iym-enlazado-interno' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo (int) $r['max_per_post']; ?></td>
							<td><?php echo empty( $r['active'] ) ? esc_html__( 'Inactiva', 'iym-enlazado-interno' ) : esc_html__( 'Activa', 'iym-enlazado-interno' ); ?></td>
							<td class="iym-el-actions">
								<a href="<?php echo esc_url( $this->url( array( 'edit' => $r['id'] ) ) ); ?>"><?php esc_html_e( 'Editar', 'iym-enlazado-interno' ); ?></a> |
								<a href="<?php echo esc_url( $toggle ); ?>"><?php echo empty( $r['active'] ) ? esc_html__( 'Activar', 'iym-enlazado-interno' ) : esc_html__( 'Desactivar', 'iym-enlazado-interno' ); ?></a> |
								<a class="iym-el-delete" href="<?php echo esc_url( $delete ); ?>" onclick="return confirm('<?php echo esc_js( __( '¿Eliminar esta regla?', 'iym-enlazado-interno' ) ); ?>');"><?php esc_html_e( 'Eliminar', 'iym-enlazado-interno' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Pestaña de ajustes.
	 */
	protected function render_settings() {
		$s          = IYM_EL_Rules::settings();
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $post_types['attachment'] );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="iym-el-card">
			<input type="hidden" name="action" value="iym_el_save_settings">
			<?php wp_nonce_field( 'iym_el_save_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Tipos de contenido', 'iym-enlazado-interno' ); ?></th>
					<td>
						<?php foreach ( $post_types as $pt ) : ?>
							<label class="iym-el-inline"><input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, (array) $s['post_types'], true ) ); ?>> <?php echo esc_html( $pt->labels->name ); ?></label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="iym-el-max-post"><?php esc_html_e( 'Máximo de enlaces por entrada', 'iym-enlazado-interno' ); ?></label></th>
					<td><input id="iym-el-max-post" type="number" min="0" max="200" name="max_per_post" class="small-text" value="<?php echo esc_attr( $s['max_per_post'] ); ?>">
						<p class="description"><?php esc_html_e( 'Total de enlaces automáticos en una misma entrada. 0 desactiva el enlazado.', 'iym-enlazado-interno' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="iym-el-max-url"><?php esc_html_e( 'Máximo de enlaces a la misma URL', 'iym-enlazado-interno' ); ?></label></th>
					<td><input id="iym-el-max-url" type="number" min="1" max="20" name="max_per_url" class="small-text" value="<?php echo esc_attr( $s['max_per_url'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'URLs ya enlazadas', 'iym-enlazado-interno' ); ?></th>
					<td><label><input type="checkbox" name="skip_linked_url" value="1" <?php checked( $s['skip_linked_url'] ); ?>> <?php esc_html_e( 'No añadir enlaces a URLs que la entrada ya enlaza manualmente', 'iym-enlazado-interno' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="iym-el-exclude"><?php esc_html_e( 'Excluir entradas (IDs)', 'iym-enlazado-interno' ); ?></label></th>
					<td><input id="iym-el-exclude" type="text" name="exclude_ids" class="regular-text" value="<?php echo esc_attr( implode( ', ', (array) $s['exclude_ids'] ) ); ?>" placeholder="12, 34, 56"></td>
				</tr>
				<tr>
					<th scope="row"><label for="iym-el-class"><?php esc_html_e( 'Clase CSS del enlace', 'iym-enlazado-interno' ); ?></label></th>
					<td><input id="iym-el-class" type="text" name="link_class" class="regular-text" value="<?php echo esc_attr( $s['link_class'] ); ?>"></td>
				</tr>
			</table>
			<?php submit_button( __( 'Guardar ajustes', 'iym-enlazado-interno' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Pestaña de importación / exportación.
	 */
	protected function render_tools() {
		?>
		<div class="iym-el-grid">
			<div class="iym-el-card">
				<h2><?php esc_html_e( 'Exportar', 'iym-enlazado-interno' ); ?></h2>
				<p><?php esc_html_e( 'Descarga todas las reglas en CSV (palabras separadas por «|»).', 'iym-enlazado-interno' ); ?></p>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=iym_el_export' ), 'iym_el_export' ) ); ?>"><?php esc_html_e( 'Descargar CSV', 'iym-enlazado-interno' ); ?></a>
			</div>
			<div class="iym-el-card">
				<h2><?php esc_html_e( 'Importar', 'iym-enlazado-interno' ); ?></h2>
				<p><?php esc_html_e( 'Columnas: keywords, url, max_per_post, case_sensitive, nofollow, new_tab, active. Solo son obligatorias las dos primeras.', 'iym-enlazado-interno' ); ?></p>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="iym_el_import">
					<?php wp_nonce_field( 'iym_el_import' ); ?>
					<p><input type="file" name="csv" accept=".csv,text/csv" required></p>
					<p><label><input type="checkbox" name="replace" value="1"> <?php esc_html_e( 'Reemplazar todas las reglas existentes', 'iym-enlazado-interno' ); ?></label></p>
					<?php submit_button( __( 'Importar CSV', 'iym-enlazado-interno' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Acciones
	 * ------------------------------------------------------------------- */

	/**
	 * Guardar regla.
	 */
	public function handle_save_rule() {
		$this->guard( 'iym_el_save_rule' );
		$data = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se sanea en IYM_EL_Rules::sanitize().
		$id   = isset( $data['id'] ) ? absint( $data['id'] ) : 0;
		$res  = IYM_EL_Rules::save( $data, $id );
		if ( is_wp_error( $res ) ) {
			$this->redirect( array( 'error' => rawurlencode( $res->get_error_message() ), 'edit' => $id ? $id : null ) );
		}
		$this->redirect( array( 'msg' => 'saved' ) );
	}

	/**
	 * Borrar regla.
	 */
	public function handle_delete_rule() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$this->guard( 'iym_el_delete_rule_' . $id );
		IYM_EL_Rules::delete( $id );
		$this->redirect( array( 'msg' => 'deleted' ) );
	}

	/**
	 * Activar / desactivar regla.
	 */
	public function handle_toggle_rule() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$this->guard( 'iym_el_toggle_rule_' . $id );
		IYM_EL_Rules::toggle( $id );
		$this->redirect( array( 'msg' => 'toggled' ) );
	}

	/**
	 * Guardar ajustes.
	 */
	public function handle_save_settings() {
		$this->guard( 'iym_el_save_settings' );
		$in    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- saneado campo a campo.
		$types = isset( $in['post_types'] ) ? array_map( 'sanitize_key', (array) $in['post_types'] ) : array();
		$ids   = isset( $in['exclude_ids'] ) ? array_values( array_filter( array_map( 'absint', preg_split( '/[\s,]+/', (string) $in['exclude_ids'] ) ) ) ) : array();

		update_option(
			IYM_EL_Rules::SETTINGS,
			array(
				'post_types'      => array_values( array_intersect( $types, get_post_types( array( 'public' => true ) ) ) ),
				'max_per_post'    => min( 200, max( 0, (int) ( isset( $in['max_per_post'] ) ? $in['max_per_post'] : 10 ) ) ),
				'max_per_url'     => min( 20, max( 1, (int) ( isset( $in['max_per_url'] ) ? $in['max_per_url'] : 1 ) ) ),
				'skip_linked_url' => empty( $in['skip_linked_url'] ) ? 0 : 1,
				'exclude_ids'     => $ids,
				'link_class'      => sanitize_html_class( isset( $in['link_class'] ) ? $in['link_class'] : '' ),
			)
		);
		IYM_EL_Rules::bump_version();
		$this->redirect( array( 'tab' => 'settings', 'msg' => 'settings' ) );
	}

	/**
	 * Exportar CSV.
	 */
	public function handle_export() {
		$this->guard( 'iym_el_export' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=iym-enlazado-interno-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM para Excel.
		fputcsv( $out, array( 'keywords', 'url', 'max_per_post', 'case_sensitive', 'nofollow', 'new_tab', 'active' ) );
		foreach ( IYM_EL_Rules::all() as $r ) {
			fputcsv( $out, array( implode( '|', $r['keywords'] ), $r['url'], $r['max_per_post'], $r['case_sensitive'], $r['nofollow'], $r['new_tab'], $r['active'] ) );
		}
		fclose( $out );
		exit;
	}

	/**
	 * Importar CSV.
	 */
	public function handle_import() {
		$this->guard( 'iym_el_import' );
		if ( empty( $_FILES['csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$this->redirect( array( 'tab' => 'tools', 'error' => rawurlencode( __( 'No se recibió ningún archivo.', 'iym-enlazado-interno' ) ) ) );
		}
		$fh = fopen( $_FILES['csv']['tmp_name'], 'r' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $fh ) {
			$this->redirect( array( 'tab' => 'tools', 'error' => rawurlencode( __( 'No se pudo leer el archivo.', 'iym-enlazado-interno' ) ) ) );
		}

		$result = $this->import_stream( $fh, ! empty( $_POST['replace'] ) );
		fclose( $fh );
		$this->redirect( array( 'tab' => 'tools', 'msg' => 'imported', 'n' => $result['imported'], 'e' => $result['errors'] ) );
	}

	/**
	 * Importa reglas desde un recurso CSV.
	 *
	 * @param resource $fh      Recurso abierto.
	 * @param bool     $replace Reemplazar las existentes.
	 * @return array{imported:int,errors:int}
	 */
	public function import_stream( $fh, $replace = false ) {
		$header = fgetcsv( $fh );
		if ( ! $header ) {
			return array( 'imported' => 0, 'errors' => 0 );
		}
		$header  = array_map(
			static function ( $h ) {
				return strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h ) ) );
			},
			$header
		);
		$has_hdr = in_array( 'keywords', $header, true ) && in_array( 'url', $header, true );
		$cols    = $has_hdr ? $header : array( 'keywords', 'url', 'max_per_post', 'case_sensitive', 'nofollow', 'new_tab', 'active' );

		$rows = array();
		if ( ! $has_hdr ) {
			$rows[] = $header;
		}
		while ( ( $row = fgetcsv( $fh ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$rows[] = $row;
		}

		$rules   = $replace ? array() : IYM_EL_Rules::all();
		$next    = (int) get_option( IYM_EL_Rules::NEXT_ID, 1 );
		$ok      = 0;
		$errors  = 0;
		$default = array( 'max_per_post' => 1, 'active' => 1 );

		foreach ( $rows as $row ) {
			if ( array( null ) === $row ) {
				continue;
			}
			$data = $default;
			foreach ( $cols as $i => $col ) {
				if ( isset( $row[ $i ] ) && '' !== trim( $row[ $i ] ) ) {
					$data[ $col ] = trim( $row[ $i ] );
				}
			}
			if ( isset( $data['keywords'] ) ) {
				$data['keywords'] = explode( '|', $data['keywords'] );
			}
			$rule = IYM_EL_Rules::sanitize( $data );
			if ( is_wp_error( $rule ) ) {
				++$errors;
				continue;
			}
			$rule['id']       = $next;
			$rules[ $next++ ] = $rule;
			++$ok;
		}

		update_option( IYM_EL_Rules::NEXT_ID, $next, false );
		IYM_EL_Rules::store( $rules );

		return array( 'imported' => $ok, 'errors' => $errors );
	}

	/* ---------------------------------------------------------------------
	 * Meta box
	 * ------------------------------------------------------------------- */

	/**
	 * Registra la meta box en los tipos activos.
	 */
	public function add_meta_box() {
		$s = IYM_EL_Rules::settings();
		foreach ( (array) $s['post_types'] as $pt ) {
			add_meta_box( 'iym-el-box', __( 'Enlazado interno', 'iym-enlazado-interno' ), array( $this, 'render_meta_box' ), $pt, 'side', 'low' );
		}
	}

	/**
	 * Contenido de la meta box.
	 *
	 * @param WP_Post $post Entrada.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'iym_el_meta', 'iym_el_meta_nonce' );
		$disabled = (bool) get_post_meta( $post->ID, IYM_EL_Linker::META_DISABLE, true );
		?>
		<label><input type="checkbox" name="iym_el_disable" value="1" <?php checked( $disabled ); ?>> <?php esc_html_e( 'No añadir enlaces automáticos en esta entrada', 'iym-enlazado-interno' ); ?></label>
		<?php
	}

	/**
	 * Guarda la meta box.
	 *
	 * @param int $post_id ID.
	 */
	public function save_meta_box( $post_id ) {
		if ( ! isset( $_POST['iym_el_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['iym_el_meta_nonce'] ), 'iym_el_meta' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( empty( $_POST['iym_el_disable'] ) ) {
			delete_post_meta( $post_id, IYM_EL_Linker::META_DISABLE );
		} else {
			update_post_meta( $post_id, IYM_EL_Linker::META_DISABLE, 1 );
		}
	}
}
