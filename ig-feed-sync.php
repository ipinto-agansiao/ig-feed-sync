<?php
/**
 * Plugin Name: IG Feed Sync
 * Description: Grelha de publicações do Instagram alimentada pelo n8n (Apify). O n8n envia as publicações para a REST API; o plugin guarda-as e mostra-as com o shortcode [igfs_feed].
 * Version: 1.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: ig-feed-sync
 * Update URI: https://github.com/ipinto-agansiao/ig-feed-sync
 *
 * @package IG_Feed_Sync
 */

defined( 'ABSPATH' ) || exit;

define( 'IGFS_VERSION', '1.2.0' );
define( 'IGFS_CPT', 'igfs_post' );
// Repositório público de onde os sites recebem as atualizações (releases do GitHub).
define( 'IGFS_UPDATE_REPO', 'https://github.com/ipinto-agansiao/ig-feed-sync/' );

/**
 * Atualizações a partir do GitHub: o WordPress mostra "Atualizar agora" quando há uma release nova.
 */
function igfs_updates() {
	if ( false !== strpos( IGFS_UPDATE_REPO, 'GITHUB_USER' ) ) {
		return; // Repositório ainda por configurar.
	}
	require_once __DIR__ . '/lib/plugin-update-checker/plugin-update-checker.php';
	\YahnisElsts\PluginUpdateChecker\v6\PucFactory::buildUpdateChecker( IGFS_UPDATE_REPO, __FILE__, 'ig-feed-sync' )
		->getVcsApi()->enableReleaseAssets();
}
igfs_updates();

/**
 * Gera a chave de acesso na ativação, se ainda não existir.
 */
function igfs_activate() {
	if ( ! get_option( 'igfs_key' ) ) {
		update_option( 'igfs_key', wp_generate_password( 48, false ), false );
	}
}
register_activation_hook( __FILE__, 'igfs_activate' );

/**
 * Tipo de conteúdo das publicações (sem páginas públicas).
 */
function igfs_register_cpt() {
	register_post_type(
		IGFS_CPT,
		array(
			'labels'              => array(
				'name'          => __( 'Instagram', 'ig-feed-sync' ),
				'singular_name' => __( 'Publicação do Instagram', 'ig-feed-sync' ),
			),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'exclude_from_search' => true,
			'menu_icon'           => 'dashicons-instagram',
			'supports'            => array( 'title', 'editor', 'thumbnail' ),
		)
	);
}
add_action( 'init', 'igfs_register_cpt' );

/**
 * Caixa "Instagram" no editor: permite adicionar publicações à mão
 * (ex.: colaborações com restrição de idade que o Apify não consegue ler).
 */
function igfs_add_meta_box() {
	add_meta_box( 'igfs_meta', __( 'Instagram', 'ig-feed-sync' ), 'igfs_meta_box', IGFS_CPT, 'side', 'high' );
}
add_action( 'add_meta_boxes', 'igfs_add_meta_box' );

/**
 * Campos da caixa.
 *
 * @param WP_Post $post Publicação.
 */
function igfs_meta_box( $post ) {
	wp_nonce_field( 'igfs_meta_save', 'igfs_meta_nonce' );
	$type = (string) get_post_meta( $post->ID, '_igfs_type', true );
	?>
	<p><label for="igfs_url"><?php esc_html_e( 'Link da publicação', 'ig-feed-sync' ); ?></label>
	<input type="url" id="igfs_url" name="igfs_url" class="widefat" placeholder="https://www.instagram.com/p/..." value="<?php echo esc_attr( get_post_meta( $post->ID, '_igfs_url', true ) ); ?>"></p>
	<p><label for="igfs_type"><?php esc_html_e( 'Tipo', 'ig-feed-sync' ); ?></label>
	<select id="igfs_type" name="igfs_type" class="widefat">
		<option value="Image" <?php selected( $type, 'Image' ); ?>><?php esc_html_e( 'Imagem', 'ig-feed-sync' ); ?></option>
		<option value="Sidecar" <?php selected( $type, 'Sidecar' ); ?>><?php esc_html_e( 'Carrossel', 'ig-feed-sync' ); ?></option>
		<option value="Video" <?php selected( $type, 'Video' ); ?>><?php esc_html_e( 'Vídeo', 'ig-feed-sync' ); ?></option>
	</select></p>
	<p class="description"><?php esc_html_e( 'A imagem da grelha é a imagem de destaque. A data de publicação define a posição na grelha.', 'ig-feed-sync' ); ?></p>
	<?php
}

/**
 * Guarda os campos da caixa.
 *
 * @param int $post_id ID da publicação.
 */
function igfs_meta_save( $post_id ) {
	if ( ! isset( $_POST['igfs_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['igfs_meta_nonce'] ) ), 'igfs_meta_save' ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$type = isset( $_POST['igfs_type'] ) ? sanitize_text_field( wp_unslash( $_POST['igfs_type'] ) ) : 'Image';
	update_post_meta( $post_id, '_igfs_type', in_array( $type, array( 'Image', 'Sidecar', 'Video' ), true ) ? $type : 'Image' );

	// O shortcode impede que o n8n duplique a publicação (e tira-a das "Publicações extra").
	$urls = igfs_extra_urls( isset( $_POST['igfs_url'] ) ? esc_url_raw( wp_unslash( $_POST['igfs_url'] ) ) : '' );
	if ( $urls ) {
		update_post_meta( $post_id, '_igfs_shortcode', key( $urls ) );
		update_post_meta( $post_id, '_igfs_url', current( $urls ) );
	}
}
add_action( 'save_post_' . IGFS_CPT, 'igfs_meta_save' );

/* -------------------------------------------------------------------------
 * REST API (usada pelo n8n)
 * ---------------------------------------------------------------------- */

/**
 * Valida o header X-IGFS-Key.
 *
 * @param WP_REST_Request $request Pedido.
 * @return true|WP_Error
 */
function igfs_rest_auth( WP_REST_Request $request ) {
	$key  = (string) get_option( 'igfs_key', '' );
	$sent = (string) $request->get_header( 'x-igfs-key' );
	if ( '' === $key || ! hash_equals( $key, $sent ) ) {
		return new WP_Error( 'igfs_forbidden', 'Chave inválida.', array( 'status' => 401 ) );
	}
	return true;
}

/**
 * Como igfs_rest_auth, mas também exige a sincronização ativa.
 *
 * @param WP_REST_Request $request Pedido.
 * @return true|WP_Error
 */
function igfs_rest_auth_write( WP_REST_Request $request ) {
	$auth = igfs_rest_auth( $request );
	if ( true !== $auth ) {
		return $auth;
	}
	if ( ! get_option( 'igfs_enabled' ) ) {
		return new WP_Error( 'igfs_disabled', 'Sincronização desligada.', array( 'status' => 403 ) );
	}
	return true;
}

/**
 * Regista as rotas igfs/v1.
 */
function igfs_register_routes() {
	register_rest_route(
		'igfs/v1',
		'/state',
		array(
			'methods'             => 'GET',
			'callback'            => 'igfs_rest_state',
			'permission_callback' => 'igfs_rest_auth',
		)
	);

	register_rest_route(
		'igfs/v1',
		'/posts',
		array(
			'methods'             => 'POST',
			'callback'            => 'igfs_rest_save_post',
			'permission_callback' => 'igfs_rest_auth_write',
			'args'                => array(
				'shortcode' => array(
					'required'          => true,
					'type'              => 'string',
					'pattern'           => '^[A-Za-z0-9_-]{5,40}$',
				),
				'url'       => array(
					'required'          => true,
					'type'              => 'string',
					'validate_callback' => function ( $value ) {
						return is_string( $value ) && 0 === strpos( $value, 'https://www.instagram.com/' );
					},
					'sanitize_callback' => 'esc_url_raw',
				),
				'caption'   => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_textarea_field',
				),
				'type'      => array(
					'type'    => 'string',
					'default' => 'Image',
					'enum'    => array( 'Image', 'Video', 'Sidecar' ),
				),
				'timestamp' => array(
					'required'          => true,
					'type'              => 'string',
					'validate_callback' => function ( $value ) {
						return is_string( $value ) && false !== strtotime( $value );
					},
				),
				'image_url' => array(
					'required' => true,
					'type'     => 'string',
					'format'   => 'uri',
				),
				'extra'     => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
		)
	);

	register_rest_route(
		'igfs/v1',
		'/profile',
		array(
			'methods'             => 'POST',
			'callback'            => 'igfs_rest_save_profile',
			'permission_callback' => 'igfs_rest_auth_write',
			'args'                => array(
				'username'    => array(
					'required' => true,
					'type'     => 'string',
					'pattern'  => '^[A-Za-z0-9._]{1,30}$',
				),
				'full_name'   => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'followers'   => array(
					'type'    => 'integer',
					'default' => 0,
					'minimum' => 0,
				),
				'posts_count' => array(
					'type'    => 'integer',
					'default' => 0,
					'minimum' => 0,
				),
				'avatar_url'  => array(
					'type'    => 'string',
					'default' => '',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'igfs_register_routes' );

/**
 * Estado para o n8n: conta a seguir, desde quando pedir e se está ativo.
 * Regista a hora do pedido.
 *
 * @return WP_REST_Response
 */
function igfs_rest_state() {
	$now = time();
	update_option( 'igfs_last_sync_at', $now, false );

	$last_post = (int) get_option( 'igfs_last_post_at', 0 );
	// Pede desde o dia da última publicação guardada (não da hora do pedido): se uma
	// execução falhar a meio, nada fica por importar. Os repetidos são ignorados.
	$since = $last_post ? gmdate( 'Y-m-d', $last_post ) : (string) get_option( 'igfs_start_date', '' );

	$username = (string) get_option( 'igfs_username', '' );

	// Publicações extra (ex.: colaborações de outra conta) ainda não guardadas.
	$extra = array();
	foreach ( igfs_extra_urls() as $shortcode => $url ) {
		if ( ! igfs_find_post( $shortcode ) ) {
			$extra[] = $url;
		}
	}

	return rest_ensure_response(
		array(
			'enabled'      => (bool) get_option( 'igfs_enabled' ) && '' !== $username && '' !== $since,
			'username'     => $username,
			'since'        => $since,
			'extra_urls'   => $extra,
			'last_post_at' => $last_post ? gmdate( 'c', $last_post ) : null,
			'last_sync_at' => gmdate( 'c', $now ),
		)
	);
}

/**
 * Cria ou atualiza uma publicação. Idempotente pelo shortcode do Instagram.
 *
 * @param WP_REST_Request $request Pedido.
 * @return WP_REST_Response|WP_Error
 */
function igfs_rest_save_post( WP_REST_Request $request ) {
	$shortcode = $request['shortcode'];
	$caption   = $request['caption'];
	$ts        = strtotime( $request['timestamp'] );
	$title     = igfs_title_from_caption( $caption, $shortcode );

	// As extra não contam para a data da última publicação: não podem saltar publicações da conta.
	$bump    = ! $request['extra'];
	$post_id = igfs_find_post( $shortcode );

	if ( $post_id ) {
		// Publicações apagadas no WP (lixo) não voltam.
		if ( 'trash' === get_post_status( $post_id ) ) {
			return rest_ensure_response( array( 'status' => 'skipped', 'id' => $post_id ) );
		}
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => $title,
				'post_content' => $caption,
			)
		);
		update_post_meta( $post_id, '_igfs_type', $request['type'] );
		if ( $bump ) {
			igfs_bump_last_post( $ts );
		}
		return rest_ensure_response( array( 'status' => 'updated', 'id' => $post_id ) );
	}

	$post_id = wp_insert_post(
		array(
			'post_type'     => IGFS_CPT,
			'post_status'   => 'publish',
			'post_title'    => $title,
			'post_content'  => $caption,
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $ts ),
			'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ) ),
			'meta_input'    => array(
				'_igfs_shortcode' => $shortcode,
				'_igfs_url'       => $request['url'],
				'_igfs_type'      => $request['type'],
			),
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	$image_id = igfs_sideload( $request['image_url'], $post_id, 'ig-' . $shortcode, $title );
	if ( is_wp_error( $image_id ) ) {
		// Sem imagem não há publicação: o n8n pára e a próxima execução tenta de novo.
		wp_delete_post( $post_id, true );
		return new WP_Error( 'igfs_image', $image_id->get_error_message(), array( 'status' => 502 ) );
	}
	set_post_thumbnail( $post_id, $image_id );

	if ( $bump ) {
		igfs_bump_last_post( $ts );
	}

	do_action( 'litespeed_purge_all' );

	return rest_ensure_response( array( 'status' => 'created', 'id' => $post_id ) );
}

/**
 * ID da publicação com este shortcode do Instagram (incluindo as do lixo), ou 0.
 *
 * @param string $shortcode Shortcode do Instagram.
 * @return int
 */
function igfs_find_post( $shortcode ) {
	$ids = get_posts(
		array(
			'post_type'        => IGFS_CPT,
			'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'trash' ),
			'meta_key'         => '_igfs_shortcode', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'       => $shortcode, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'fields'           => 'ids',
			'numberposts'      => 1,
			'suppress_filters' => true,
		)
	);
	return $ids ? (int) $ids[0] : 0;
}

/**
 * Publicações extra das definições, indexadas pelo shortcode. Só aceita links do Instagram.
 *
 * @param string|null $raw Texto a ler (por defeito, a opção guardada).
 * @return array<string,string>
 */
function igfs_extra_urls( $raw = null ) {
	$raw  = null === $raw ? (string) get_option( 'igfs_extra_urls', '' ) : $raw;
	$urls = array();
	foreach ( preg_split( '/\s+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $line ) {
		if ( preg_match( '#^https://(?:www\.)?instagram\.com/(?:[A-Za-z0-9._]+/)?(?:p|reel)/([A-Za-z0-9_-]{5,40})#', $line, $m ) ) {
			$urls[ $m[1] ] = 'https://www.instagram.com/p/' . $m[1] . '/';
		}
	}
	return $urls;
}

/**
 * Guarda a data da publicação mais recente recebida (novas e já existentes).
 *
 * @param int $ts Timestamp da publicação.
 */
function igfs_bump_last_post( $ts ) {
	if ( $ts > (int) get_option( 'igfs_last_post_at', 0 ) ) {
		update_option( 'igfs_last_post_at', $ts, false );
	}
}

/**
 * Guarda os dados do perfil. A foto só é descarregada de 7 em 7 dias
 * (o URL do Instagram muda a cada pedido).
 *
 * @param WP_REST_Request $request Pedido.
 * @return WP_REST_Response
 */
function igfs_rest_save_profile( WP_REST_Request $request ) {
	$old     = (array) get_option( 'igfs_profile', array() );
	$profile = array(
		'username'    => $request['username'],
		'full_name'   => $request['full_name'],
		'followers'   => (int) $request['followers'],
		'posts_count' => (int) $request['posts_count'],
		'avatar_id'   => isset( $old['avatar_id'] ) ? (int) $old['avatar_id'] : 0,
		'avatar_at'   => isset( $old['avatar_at'] ) ? (int) $old['avatar_at'] : 0,
	);

	$stale = ! $profile['avatar_id'] || ! wp_get_attachment_url( $profile['avatar_id'] ) || $profile['avatar_at'] < time() - WEEK_IN_SECONDS;
	if ( $request['avatar_url'] && $stale ) {
		$avatar_id = igfs_sideload( $request['avatar_url'], 0, 'ig-avatar-' . $profile['username'], $profile['username'] );
		if ( ! is_wp_error( $avatar_id ) ) {
			if ( $profile['avatar_id'] ) {
				wp_delete_attachment( $profile['avatar_id'], true );
			}
			$profile['avatar_id'] = $avatar_id;
			$profile['avatar_at'] = time();
		}
	}

	update_option( 'igfs_profile', $profile, false );
	return rest_ensure_response( array( 'status' => 'saved' ) );
}

/**
 * Título a partir da primeira linha da legenda.
 *
 * @param string $caption   Legenda.
 * @param string $shortcode Shortcode do Instagram (alternativa).
 * @return string
 */
function igfs_title_from_caption( $caption, $shortcode ) {
	$line = trim( strtok( $caption, "\n" ) );
	if ( '' === $line ) {
		return $shortcode;
	}
	return mb_strlen( $line ) > 80 ? rtrim( mb_substr( $line, 0, 79 ) ) . '…' : $line;
}

/**
 * Descarrega uma imagem da CDN do Instagram para a biblioteca de media.
 *
 * @param string $url     URL da imagem.
 * @param int    $post_id Publicação a que fica associada (0 = nenhuma).
 * @param string $name    Nome do ficheiro, sem extensão.
 * @param string $desc    Descrição/título do anexo.
 * @return int|WP_Error ID do anexo.
 */
function igfs_sideload( $url, $post_id, $name, $desc ) {
	$host    = (string) wp_parse_url( $url, PHP_URL_HOST );
	$allowed = false;
	foreach ( (array) apply_filters( 'igfs_image_hosts', array( 'cdninstagram.com', 'fbcdn.net' ) ) as $domain ) {
		if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
			$allowed = true;
		}
	}
	// Só imagens da CDN do Instagram: evita que a chave sirva para descarregar outra coisa.
	if ( ! $allowed || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
		return new WP_Error( 'igfs_host', 'Domínio da imagem não permitido: ' . $host );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$tmp = download_url( $url, 30 );
	if ( is_wp_error( $tmp ) ) {
		return $tmp;
	}

	$exts = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
	);
	$mime = wp_get_image_mime( $tmp );
	if ( ! isset( $exts[ $mime ] ) ) {
		wp_delete_file( $tmp );
		return new WP_Error( 'igfs_mime', 'O ficheiro descarregado não é uma imagem.' );
	}

	$id = media_handle_sideload(
		array(
			'name'     => sanitize_file_name( $name ) . '.' . $exts[ $mime ],
			'tmp_name' => $tmp,
		),
		$post_id,
		$desc
	);
	if ( is_wp_error( $id ) ) {
		wp_delete_file( $tmp );
	}
	return $id;
}

/* -------------------------------------------------------------------------
 * Grelha: [igfs_feed layout="cards|highlight" num="8" cols="4" title="" button=""]
 * ---------------------------------------------------------------------- */

/**
 * Regista o CSS (só é pedido quando o shortcode é usado).
 */
function igfs_register_assets() {
	// Versão pela data do ficheiro: o browser recebe sempre o CSS atualizado.
	wp_register_style( 'igfs-feed', plugins_url( 'assets/feed.css', __FILE__ ), array(), (string) filemtime( __DIR__ . '/assets/feed.css' ) );
}
add_action( 'wp_enqueue_scripts', 'igfs_register_assets' );

/**
 * Shortcode da grelha.
 *
 * @param array|string $atts Atributos.
 * @return string
 */
function igfs_feed_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'layout' => 'cards',
			'num'    => 8,
			'cols'   => 4,
			'title'    => '',
			'button'   => '',
			'featured' => '',
			'ratio'    => '',
			'width'    => '',
		),
		$atts,
		'igfs_feed'
	);

	$layout = in_array( $atts['layout'], array( 'cards', 'highlight' ), true ) ? $atts['layout'] : 'cards';
	$cols   = min( max( absint( $atts['cols'] ), 1 ), 6 );
	// Destaque (1.ª publicação em 2x2) e proporção das imagens: por defeito, como no layout.
	$featured = '' === $atts['featured'] ? 'highlight' === $layout : (bool) $atts['featured'];
	$tall     = 'tall' === $atts['featured']; // 1 coluna x 2 linhas em vez de 2x2.
	$ratio    = '' === $atts['ratio'] ? '1' : $atts['ratio'];
	if ( ! preg_match( '#^(auto|\d+(\.\d+)?(/\d+(\.\d+)?)?)$#', $ratio ) ) {
		$ratio = '1';
	}

	$query = new WP_Query(
		array(
			'post_type'              => IGFS_CPT,
			'post_status'            => 'publish',
			'posts_per_page'         => min( max( absint( $atts['num'] ), 1 ), 50 ),
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		)
	);
	if ( ! $query->have_posts() ) {
		return '';
	}

	wp_enqueue_style( 'igfs-feed' );

	$profile  = (array) get_option( 'igfs_profile', array() );
	$username = ! empty( $profile['username'] ) ? $profile['username'] : (string) get_option( 'igfs_username', '' );
	$title    = '' !== $atts['title'] ? $atts['title'] : '@' . $username;
	$avatar   = (int) get_option( 'igfs_avatar_id', 0 );
	if ( ! $avatar && ! empty( $profile['avatar_id'] ) ) {
		$avatar = (int) $profile['avatar_id'];
	}
	$prof_url = 'https://www.instagram.com/' . rawurlencode( $username ) . '/';
	$icons    = array(
		'Sidecar' => '<svg class="igfs__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h11a3 3 0 0 1 3 3v11h-2V6a1 1 0 0 0-1-1H7z"/><path d="M5 7h10a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2zm0 2v10h10V9z"/></svg>',
		'Video'   => '<svg class="igfs__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4.5v15L19.5 12z"/></svg>',
	);

	ob_start();
	?>
	<div class="igfs igfs--<?php echo esc_attr( $layout ); ?><?php echo $featured ? ' igfs--featured' : ''; ?><?php echo $tall ? ' igfs--featured-tall' : ''; ?>" style="--igfs-cols:<?php echo esc_attr( $cols ); ?>;--igfs-ratio:<?php echo esc_attr( $ratio ); ?>;--igfs-rest:<?php echo esc_attr( max( $cols - 1, 1 ) ); ?><?php echo absint( $atts['width'] ) ? ';--igfs-max-width:' . absint( $atts['width'] ) . 'px' : ''; ?>">
		<a class="igfs__header" href="<?php echo esc_url( $prof_url ); ?>" target="_blank" rel="noopener">
			<?php if ( 'cards' === $layout && $avatar ) : ?>
				<?php echo wp_get_attachment_image( $avatar, 'thumbnail', false, array( 'class' => 'igfs__avatar', 'alt' => '', 'loading' => 'lazy' ) ); ?>
			<?php else : ?>
				<span class="igfs__avatar igfs__avatar--icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10zm0 8.2a3.2 3.2 0 1 1 0-6.4 3.2 3.2 0 0 1 0 6.4zM17.3 5.5a1.2 1.2 0 1 0 0 2.4 1.2 1.2 0 0 0 0-2.4zM12 2c-2.7 0-3 0-4.1.1C4.3 2.2 2.2 4.3 2.1 7.9 2 9 2 9.3 2 12s0 3 .1 4.1c.1 3.6 2.2 5.7 5.8 5.8 1.1.1 1.4.1 4.1.1s3 0 4.1-.1c3.6-.1 5.7-2.2 5.8-5.8.1-1.1.1-1.4.1-4.1s0-3-.1-4.1c-.1-3.6-2.2-5.7-5.8-5.8C15 2 14.7 2 12 2zm0 1.8c2.7 0 3 0 4 .1 2.6.1 3.9 1.4 4 4 .1 1 .1 1.4.1 4.1s0 3-.1 4c-.1 2.6-1.4 3.9-4 4-1 .1-1.3.1-4 .1s-3 0-4-.1c-2.6-.1-3.9-1.4-4-4-.1-1-.1-1.4-.1-4s0-3 .1-4c.1-2.6 1.4-3.9 4-4 1-.1 1.3-.1 4-.1z"/></svg></span>
			<?php endif; ?>
			<?php if ( 'cards' === $layout ) : ?>
				<span class="igfs__name" data-no-translation><?php echo esc_html( $username ); ?></span>
				<?php if ( ! empty( $profile['posts_count'] ) ) : ?>
					<span class="igfs__stat"><?php echo esc_html( sprintf( /* translators: %s: número de publicações */ __( '%s publicações', 'ig-feed-sync' ), number_format_i18n( $profile['posts_count'] ) ) ); ?></span>
				<?php endif; ?>
				<?php if ( ! empty( $profile['followers'] ) ) : ?>
					<span class="igfs__stat"><?php echo esc_html( sprintf( /* translators: %s: número de seguidores */ __( '%s seguidores', 'ig-feed-sync' ), number_format_i18n( $profile['followers'] ) ) ); ?></span>
				<?php endif; ?>
			<?php else : ?>
				<span class="igfs__name" data-no-translation><?php echo esc_html( $title ); ?></span>
			<?php endif; ?>
		</a>
		<ul class="igfs__grid" data-no-translation>
			<?php
			while ( $query->have_posts() ) :
				$query->the_post();
				$type = (string) get_post_meta( get_the_ID(), '_igfs_type', true );
				?>
				<li class="igfs__item">
					<?php if ( 'cards' === $layout ) : ?>
						<div class="igfs__meta">
							<?php if ( $avatar ) : ?>
								<?php echo wp_get_attachment_image( $avatar, 'thumbnail', false, array( 'class' => 'igfs__meta-avatar', 'alt' => '', 'loading' => 'lazy' ) ); ?>
							<?php endif; ?>
							<span class="igfs__meta-text">
								<span class="igfs__meta-user"><?php echo esc_html( $username ); ?></span>
								<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date( 'M j' ) ); ?></time>
							</span>
						</div>
					<?php endif; ?>
					<a class="igfs__media" href="<?php echo esc_url( get_post_meta( get_the_ID(), '_igfs_url', true ) ); ?>" target="_blank" rel="noopener">
						<?php
						echo get_the_post_thumbnail(
							null,
							'medium_large',
							array(
								'alt'     => wp_trim_words( get_the_content(), 20 ),
								'loading' => 'lazy',
								'sizes'   => '(max-width: 767px) 50vw, ' . round( 100 / $cols ) . 'vw',
							)
						);
						echo isset( $icons[ $type ] ) ? $icons[ $type ] : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG fixo.
						?>
					</a>
				</li>
			<?php endwhile; ?>
		</ul>
		<?php if ( '' !== $atts['button'] ) : ?>
			<a class="igfs__button" href="<?php echo esc_url( $prof_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $atts['button'] ); ?></a>
		<?php endif; ?>
	</div>
	<?php
	wp_reset_postdata();
	return ob_get_clean();
}
add_shortcode( 'igfs_feed', 'igfs_feed_shortcode' );

/* -------------------------------------------------------------------------
 * Definições (Definições > IG Feed Sync)
 * ---------------------------------------------------------------------- */

/**
 * Regista as opções.
 */
function igfs_register_settings() {
	register_setting( 'igfs', 'igfs_username', array( 'sanitize_callback' => 'igfs_sanitize_username' ) );
	register_setting( 'igfs', 'igfs_start_date', array( 'sanitize_callback' => 'igfs_sanitize_date' ) );
	register_setting(
		'igfs',
		'igfs_extra_urls',
		array(
			'sanitize_callback' => function ( $value ) {
				return implode( "\n", igfs_extra_urls( (string) $value ) );
			},
		)
	);
	register_setting(
		'igfs',
		'igfs_avatar_id',
		array(
			'sanitize_callback' => function ( $value ) {
				$value = absint( $value );
				return $value && wp_attachment_is_image( $value ) ? $value : 0;
			},
		)
	);
	register_setting(
		'igfs',
		'igfs_enabled',
		array(
			'sanitize_callback' => function ( $value ) {
				return $value ? 1 : 0;
			},
		)
	);
}
add_action( 'admin_init', 'igfs_register_settings' );

/**
 * Nome de utilizador do Instagram, sem @.
 *
 * @param string $value Valor.
 * @return string
 */
function igfs_sanitize_username( $value ) {
	$value = ltrim( trim( (string) $value ), '@' );
	return preg_match( '/^[A-Za-z0-9._]{1,30}$/', $value ) ? $value : '';
}

/**
 * Data no formato AAAA-MM-DD.
 *
 * @param string $value Valor.
 * @return string
 */
function igfs_sanitize_date( $value ) {
	$value = (string) $value;
	return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
}

/**
 * Seletor da biblioteca de media na página de definições.
 *
 * @param string $hook Página atual do admin.
 */
function igfs_admin_assets( $hook ) {
	if ( 'settings_page_igfs' !== $hook ) {
		return;
	}
	wp_enqueue_media();
	wp_register_script( 'igfs-admin', false, array( 'media-editor' ), IGFS_VERSION, true );
	wp_enqueue_script( 'igfs-admin' );
	wp_add_inline_script(
		'igfs-admin',
		"document.addEventListener('click',function(e){var b=e.target.closest('[data-igfs-avatar]');if(!b){return;}e.preventDefault();var input=document.getElementById('igfs_avatar_id'),prev=document.getElementById('igfs-avatar-preview');if('remove'===b.dataset.igfsAvatar){input.value='';prev.textContent='';return;}var frame=wp.media({title:b.textContent,library:{type:'image'},multiple:false});frame.on('select',function(){var a=frame.state().get('selection').first().toJSON(),img=document.createElement('img');input.value=a.id;img.src=(a.sizes&&a.sizes.thumbnail?a.sizes.thumbnail:a).url;img.width=80;img.height=80;img.style.cssText='border-radius:50%;object-fit:cover';prev.textContent='';prev.appendChild(img);});frame.open();});"
	);
}
add_action( 'admin_enqueue_scripts', 'igfs_admin_assets' );

/**
 * Menu.
 */
function igfs_admin_menu() {
	add_options_page( 'IG Feed Sync', 'IG Feed Sync', 'manage_options', 'igfs', 'igfs_settings_page' );
}
add_action( 'admin_menu', 'igfs_admin_menu' );

/**
 * Gera uma chave nova (a antiga deixa de funcionar).
 */
function igfs_regenerate_key() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sem permissão.', 'ig-feed-sync' ) );
	}
	check_admin_referer( 'igfs_regenerate_key' );
	update_option( 'igfs_key', wp_generate_password( 48, false ), false );
	wp_safe_redirect( admin_url( 'options-general.php?page=igfs&key=new' ) );
	exit;
}
add_action( 'admin_post_igfs_regenerate_key', 'igfs_regenerate_key' );

/**
 * Página de definições.
 */
function igfs_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$fmt       = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	$last_sync = (int) get_option( 'igfs_last_sync_at', 0 );
	$last_post = (int) get_option( 'igfs_last_post_at', 0 );
	$count     = wp_count_posts( IGFS_CPT );
	?>
	<div class="wrap">
		<h1>IG Feed Sync</h1>
		<?php if ( isset( $_GET['key'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Chave nova gerada. Atualize a credencial no n8n.', 'ig-feed-sync' ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'igfs' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="igfs_username"><?php esc_html_e( 'Conta do Instagram', 'ig-feed-sync' ); ?></label></th>
					<td><input name="igfs_username" id="igfs_username" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'igfs_username', '' ) ); ?>" placeholder="nomedaconta"></td>
				</tr>
				<tr>
					<th scope="row"><label for="igfs_start_date"><?php esc_html_e( 'Importar desde', 'ig-feed-sync' ); ?></label></th>
					<td><input name="igfs_start_date" id="igfs_start_date" type="date" value="<?php echo esc_attr( get_option( 'igfs_start_date', '' ) ); ?>">
					<p class="description"><?php esc_html_e( 'Usada só na primeira importação. Depois o pedido começa no dia da última publicação guardada.', 'ig-feed-sync' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="igfs_extra_urls"><?php esc_html_e( 'Publicações extra', 'ig-feed-sync' ); ?></label></th>
					<td><textarea name="igfs_extra_urls" id="igfs_extra_urls" rows="4" class="large-text code" placeholder="https://www.instagram.com/p/..."><?php echo esc_textarea( get_option( 'igfs_extra_urls', '' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Um link por linha. Para colaborações que pertencem a outra conta e por isso não vêm no pedido normal. São importadas na próxima execução do n8n.', 'ig-feed-sync' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Imagem do perfil', 'ig-feed-sync' ); ?></th>
					<td>
						<?php $avatar_id = (int) get_option( 'igfs_avatar_id', 0 ); ?>
						<input type="hidden" name="igfs_avatar_id" id="igfs_avatar_id" value="<?php echo esc_attr( $avatar_id ? $avatar_id : '' ); ?>">
						<span id="igfs-avatar-preview"><?php echo $avatar_id ? wp_get_attachment_image( $avatar_id, array( 80, 80 ), false, array( 'style' => 'border-radius:50%;object-fit:cover' ) ) : ''; ?></span>
						<p>
							<button type="button" class="button" data-igfs-avatar="choose"><?php esc_html_e( 'Escolher imagem', 'ig-feed-sync' ); ?></button>
							<button type="button" class="button-link" data-igfs-avatar="remove"><?php esc_html_e( 'Remover', 'ig-feed-sync' ); ?></button>
						</p>
						<p class="description"><?php esc_html_e( 'Substitui a foto que vem do Instagram. Vazio = usa a do Instagram.', 'ig-feed-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Sincronização', 'ig-feed-sync' ); ?></th>
					<td><label><input name="igfs_enabled" type="checkbox" value="1" <?php checked( get_option( 'igfs_enabled' ) ); ?>> <?php esc_html_e( 'Ativa (o n8n pode escrever)', 'ig-feed-sync' ); ?></label></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2><?php esc_html_e( 'Ligação ao n8n', 'ig-feed-sync' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'URL do site', 'ig-feed-sync' ); ?></th>
				<td><code><?php echo esc_html( home_url() ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Chave (header X-IGFS-Key)', 'ig-feed-sync' ); ?></th>
				<td><input type="text" class="large-text code" readonly onfocus="this.select()" value="<?php echo esc_attr( get_option( 'igfs_key', '' ) ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Último pedido do n8n', 'ig-feed-sync' ); ?></th>
				<td><?php echo $last_sync ? esc_html( wp_date( $fmt, $last_sync ) ) : '—'; ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Publicação mais recente', 'ig-feed-sync' ); ?></th>
				<td><?php echo $last_post ? esc_html( wp_date( $fmt, $last_post ) ) : '—'; ?> (<?php echo esc_html( (int) $count->publish ); ?> <?php esc_html_e( 'publicadas', 'ig-feed-sync' ); ?>)</td>
			</tr>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'A chave atual deixa de funcionar. Continuar?', 'ig-feed-sync' ) ); ?>');">
			<input type="hidden" name="action" value="igfs_regenerate_key">
			<?php wp_nonce_field( 'igfs_regenerate_key' ); ?>
			<?php submit_button( __( 'Gerar chave nova', 'ig-feed-sync' ), 'secondary', 'submit', false ); ?>
		</form>
		<p><?php esc_html_e( 'Shortcode:', 'ig-feed-sync' ); ?> <code>[igfs_feed layout="cards" num="9" featured="1"]</code> · <code>[igfs_feed layout="highlight" num="9" title="#conta" button="Ver mais no Instagram"]</code> · <?php esc_html_e( 'Opções: featured="1|tall|0" (1.ª em 2x2, em 1x2 ou normal), ratio="1|4/5|auto", width="1100" (largura máxima em px).', 'ig-feed-sync' ); ?></p>
	</div>
	<?php
}
