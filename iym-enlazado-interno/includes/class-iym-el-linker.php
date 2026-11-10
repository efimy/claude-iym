<?php
/**
 * Motor de enlazado: inserta enlaces en el HTML al renderizar.
 *
 * @package IYM_Enlazado_Interno
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inserta enlaces internos en `the_content` sin tocar la base de datos.
 */
class IYM_EL_Linker {

	const META_DISABLE = '_iym_el_disable';
	const CACHE_GROUP  = 'iym_el';
	const CHUNK_SIZE   = 300;

	/**
	 * Etiquetas dentro de las cuales nunca se enlaza.
	 *
	 * @var string[]
	 */
	protected static $skip_tags = array( 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'script', 'style', 'code', 'pre', 'button', 'textarea', 'select', 'label', 'svg', 'figcaption' );

	/**
	 * Instancia única.
	 *
	 * @var IYM_EL_Linker|null
	 */
	protected static $instance = null;

	/**
	 * Estado del enlazado en curso.
	 *
	 * @var array
	 */
	protected $state = array();

	/**
	 * Devuelve la instancia.
	 *
	 * @return IYM_EL_Linker
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			add_filter( 'the_content', array( self::$instance, 'filter_content' ), 20 );
		}
		return self::$instance;
	}

	/**
	 * Filtro de `the_content`.
	 *
	 * @param string $content HTML.
	 * @return string
	 */
	public function filter_content( $content ) {
		if ( ! is_string( $content ) || '' === $content || is_admin() || is_feed() ) {
			return $content;
		}
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post = get_post();
		if ( ! $post || ! $this->is_enabled_for( $post ) ) {
			return $content;
		}

		$key    = 'c_' . $post->ID . '_' . md5( $content . '|' . IYM_EL_Rules::version() );
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$linked = $this->link( $content, $post->ID );
		wp_cache_set( $key, $linked, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $linked;
	}

	/**
	 * ¿Se debe enlazar en esta entrada?
	 *
	 * @param WP_Post $post Entrada.
	 * @return bool
	 */
	public function is_enabled_for( $post ) {
		$settings = IYM_EL_Rules::settings();
		if ( ! in_array( $post->post_type, (array) $settings['post_types'], true ) ) {
			return false;
		}
		if ( in_array( (int) $post->ID, array_map( 'intval', (array) $settings['exclude_ids'] ), true ) ) {
			return false;
		}
		if ( get_post_meta( $post->ID, self::META_DISABLE, true ) ) {
			return false;
		}
		/**
		 * Permite desactivar el enlazado en una entrada concreta.
		 *
		 * @param bool    $enabled Activado.
		 * @param WP_Post $post    Entrada.
		 */
		return (bool) apply_filters( 'iym_el_enabled_for_post', true, $post );
	}

	/**
	 * Inserta los enlaces en un HTML.
	 *
	 * @param string $html    HTML.
	 * @param int    $post_id Entrada actual (para evitar autoenlaces).
	 * @param array  $rules   Reglas (por defecto, las activas).
	 * @return string
	 */
	public function link( $html, $post_id = 0, $rules = null ) {
		$settings = IYM_EL_Rules::settings();
		$rules    = null === $rules ? IYM_EL_Rules::active() : $rules;
		$max      = (int) $settings['max_per_post'];
		if ( empty( $rules ) || $max < 1 ) {
			return $html;
		}

		$self_url = $post_id ? $this->normalize_url( (string) get_permalink( $post_id ) ) : '';
		$existing = ! empty( $settings['skip_linked_url'] ) ? $this->existing_urls( $html ) : array();

		// Prepara las reglas aplicables y el mapa de palabras clave.
		$prepared = array();
		$exact    = array();
		$folded   = array();
		$patterns = array();

		foreach ( $rules as $rule ) {
			$url = $this->rule_url( $rule );
			if ( '' === $url ) {
				continue;
			}
			$norm = $this->normalize_url( $url );
			if ( ( $post_id && ! empty( $rule['target_id'] ) && (int) $rule['target_id'] === (int) $post_id ) || $norm === $self_url || isset( $existing[ $norm ] ) ) {
				continue;
			}

			$id              = (int) $rule['id'];
			$prepared[ $id ] = array(
				'url'      => $url,
				'norm'     => $norm,
				'max'      => max( 1, (int) $rule['max_per_post'] ),
				'nofollow' => ! empty( $rule['nofollow'] ),
				'new_tab'  => ! empty( $rule['new_tab'] ),
			);

			foreach ( (array) $rule['keywords'] as $kw ) {
				foreach ( $this->keyword_variants( $kw ) as $variant ) {
					$canon = $this->canon( $variant );
					if ( ! empty( $rule['case_sensitive'] ) ) {
						if ( ! isset( $exact[ $canon ] ) ) {
							$exact[ $canon ] = $id;
						}
						$patterns[ $variant ] = false;
					} else {
						$lower = $this->lower( $canon );
						if ( ! isset( $folded[ $lower ] ) ) {
							$folded[ $lower ] = $id;
						}
						if ( ! isset( $patterns[ $variant ] ) ) {
							$patterns[ $variant ] = true;
						}
					}
				}
			}
		}

		if ( empty( $prepared ) || empty( $patterns ) ) {
			return $html;
		}

		// Las más largas primero para que «seo local» gane a «seo».
		$keys = array_keys( $patterns );
		usort(
			$keys,
			function ( $a, $b ) {
				return $this->length( $b ) - $this->length( $a );
			}
		);

		$this->state = array(
			'rules'    => $prepared,
			'exact'    => $exact,
			'folded'   => $folded,
			'counts'   => array(),
			'url_hits' => array(),
			'total'    => 0,
			'max'      => $max,
			'max_url'  => max( 1, (int) $settings['max_per_url'] ),
			'class'    => sanitize_html_class( $settings['link_class'] ),
		);

		foreach ( array_chunk( $keys, self::CHUNK_SIZE ) as $chunk ) {
			if ( $this->state['total'] >= $max ) {
				break;
			}
			$alts = array();
			foreach ( $chunk as $kw ) {
				$quoted = str_replace( '\ ', ' ', preg_quote( $kw, '/' ) );
				$quoted = preg_replace( '/ +/', '\s+', $quoted );
				$alts[] = $patterns[ $kw ] ? '(?i:' . $quoted . ')' : $quoted;
			}
			$regex = '/(?<![\p{L}\p{N}_])(?:' . implode( '|', $alts ) . ')(?![\p{L}\p{N}_])/u';
			$html  = $this->apply_regex( $html, $regex );
		}

		$this->state = array();
		return $html;
	}

	/**
	 * Aplica la expresión solo a los nodos de texto permitidos.
	 *
	 * @param string $html  HTML.
	 * @param string $regex Expresión.
	 * @return string
	 */
	protected function apply_regex( $html, $regex ) {
		$parts = preg_split( '/(<!--.*?-->|<[^>]+>)/su', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return $html;
		}

		$depth = 0;
		$out   = '';
		foreach ( $parts as $i => $part ) {
			if ( 1 === $i % 2 ) {
				$out .= $part;
				if ( preg_match( '#^<(/?)([a-z][a-z0-9]*)\b[^>]*?(/?)>$#i', $part, $m ) && in_array( strtolower( $m[2] ), self::$skip_tags, true ) && '/' !== $m[3] ) {
					$depth = '/' === $m[1] ? max( 0, $depth - 1 ) : $depth + 1;
				}
				continue;
			}
			if ( $depth > 0 || '' === trim( $part ) || $this->state['total'] >= $this->state['max'] ) {
				$out .= $part;
				continue;
			}
			$replaced = preg_replace_callback( $regex, array( $this, 'replace_match' ), $part );
			$out     .= null === $replaced ? $part : $replaced;
		}
		return $out;
	}

	/**
	 * Callback por coincidencia: decide si enlaza según los límites.
	 *
	 * @param array $m Coincidencia.
	 * @return string
	 */
	public function replace_match( $m ) {
		$text = $m[0];
		$s    = &$this->state;
		if ( $s['total'] >= $s['max'] ) {
			return $text;
		}

		$canon = $this->canon( $text );
		if ( isset( $s['exact'][ $canon ] ) ) {
			$id = $s['exact'][ $canon ];
		} elseif ( isset( $s['folded'][ $this->lower( $canon ) ] ) ) {
			$id = $s['folded'][ $this->lower( $canon ) ];
		} else {
			return $text;
		}

		$rule  = $s['rules'][ $id ];
		$count = isset( $s['counts'][ $id ] ) ? $s['counts'][ $id ] : 0;
		$hits  = isset( $s['url_hits'][ $rule['norm'] ] ) ? $s['url_hits'][ $rule['norm'] ] : 0;
		if ( $count >= $rule['max'] || $hits >= $s['max_url'] ) {
			return $text;
		}

		$s['counts'][ $id ]              = $count + 1;
		$s['url_hits'][ $rule['norm'] ] = $hits + 1;
		++$s['total'];

		$rel   = array();
		$attrs = ' href="' . esc_url( $rule['url'] ) . '"';
		if ( $s['class'] ) {
			$attrs .= ' class="' . esc_attr( $s['class'] ) . '"';
		}
		if ( $rule['new_tab'] ) {
			$attrs .= ' target="_blank"';
			$rel[]  = 'noopener';
		}
		if ( $rule['nofollow'] ) {
			$rel[] = 'nofollow';
		}
		if ( $rel ) {
			$attrs .= ' rel="' . esc_attr( implode( ' ', $rel ) ) . '"';
		}

		return '<a' . $attrs . '>' . $text . '</a>';
	}

	/**
	 * URL de destino de la regla (sigue el permalink si la entrada cambia de slug).
	 *
	 * @param array $rule Regla.
	 * @return string
	 */
	protected function rule_url( $rule ) {
		if ( ! empty( $rule['target_id'] ) ) {
			if ( 'publish' !== get_post_status( (int) $rule['target_id'] ) ) {
				return '';
			}
			$permalink = get_permalink( (int) $rule['target_id'] );
			if ( $permalink ) {
				return $permalink;
			}
		}
		return isset( $rule['url'] ) ? (string) $rule['url'] : '';
	}

	/**
	 * URLs ya enlazadas en el contenido.
	 *
	 * @param string $html HTML.
	 * @return array Mapa url normalizada => true.
	 */
	protected function existing_urls( $html ) {
		$urls = array();
		if ( preg_match_all( '/<a\s[^>]*href\s*=\s*(["\'])(.*?)\1/is', $html, $m ) ) {
			foreach ( $m[2] as $href ) {
				$urls[ $this->normalize_url( html_entity_decode( $href, ENT_QUOTES, 'UTF-8' ) ) ] = true;
			}
		}
		return $urls;
	}

	/**
	 * Normaliza una URL para compararla.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public function normalize_url( $url ) {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( '/' === $url[0] && ( ! isset( $url[1] ) || '/' !== $url[1] ) ) {
			$url = home_url( $url );
		}
		$url = preg_replace( '/#.*$/', '', $url );
		$url = preg_replace( '#^https?://(www\.)?#i', '', $url );
		return strtolower( untrailingslashit( $url ) );
	}

	/**
	 * Variantes de una palabra clave tal y como pueden aparecer en el HTML.
	 *
	 * @param string $kw Palabra clave.
	 * @return string[]
	 */
	protected function keyword_variants( $kw ) {
		$kw       = trim( (string) $kw );
		$variants = array( esc_html( $kw ) );
		if ( function_exists( 'wptexturize' ) ) {
			$variants[] = wptexturize( esc_html( $kw ) );
		}
		return array_values( array_unique( array_filter( $variants, 'strlen' ) ) );
	}

	/**
	 * Forma canónica: espacios colapsados.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	protected function canon( $text ) {
		return preg_replace( '/\s+/u', ' ', $text );
	}

	/**
	 * Minúsculas multibyte.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	protected function lower( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Longitud multibyte.
	 *
	 * @param string $text Texto.
	 * @return int
	 */
	protected function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}
}
