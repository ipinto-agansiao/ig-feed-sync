<?php
/**
 * Plugin Name: IG Feed Sync
 * Description: Grelha de publicações do Instagram alimentada pelo n8n (Apify). O n8n envia as publicações para a REST API; o plugin guarda-as e mostra-as com o shortcode [igfs_feed].
 * Version: 1.4.4
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: ig-feed-sync
 * Update URI: https://github.com/ipinto-agansiao/ig-feed-sync
 *
 * @package IG_Feed_Sync
 */

defined( 'ABSPATH' ) || exit;

define( 'IGFS_VERSION', '1.4.4' );
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
	$type    = (string) get_post_meta( $post->ID, '_igfs_type', true );
	$account = (string) get_post_meta( $post->ID, '_igfs_account', true );
	?>
	<p><label for="igfs_url"><?php esc_html_e( 'Link da publicação', 'ig-feed-sync' ); ?></label>
	<input type="url" id="igfs_url" name="igfs_url" class="widefat" placeholder="https://www.instagram.com/p/..." value="<?php echo esc_attr( get_post_meta( $post->ID, '_igfs_url', true ) ); ?>"></p>
	<p><label for="igfs_type"><?php esc_html_e( 'Tipo', 'ig-feed-sync' ); ?></label>
	<select id="igfs_type" name="igfs_type" class="widefat">
		<option value="Image" <?php selected( $type, 'Image' ); ?>><?php esc_html_e( 'Imagem', 'ig-feed-sync' ); ?></option>
		<option value="Sidecar" <?php selected( $type, 'Sidecar' ); ?>><?php esc_html_e( 'Carrossel', 'ig-feed-sync' ); ?></option>
		<option value="Video" <?php selected( $type, 'Video' ); ?>><?php esc_html_e( 'Vídeo', 'ig-feed-sync' ); ?></option>
	</select></p>
	<?php if ( count( igfs_accounts() ) > 1 ) : ?>
		<p><label for="igfs_account"><?php esc_html_e( 'Conta', 'ig-feed-sync' ); ?></label>
		<select id="igfs_account" name="igfs_account" class="widefat">
			<?php foreach ( array_keys( igfs_accounts() ) as $name ) : ?>
				<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $account, $name ); ?>><?php echo esc_html( $name ); ?></option>
			<?php endforeach; ?>
		</select></p>
	<?php endif; ?>
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
	$account = igfs_account( isset( $_POST['igfs_account'] ) ? sanitize_text_field( wp_unslash( $_POST['igfs_account'] ) ) : '' );
	if ( '' !== $account ) {
		update_post_meta( $post_id, '_igfs_account', $account );
	}

	// O shortcode impede que o n8n duplique a publicação (e tira-a das "Publicações extra").
	$urls = igfs_extra_urls( isset( $_POST['igfs_url'] ) ? esc_url_raw( wp_unslash( $_POST['igfs_url'] ) ) : '' );
	if ( $urls ) {
		update_post_meta( $post_id, '_igfs_shortcode', key( $urls ) );
		update_post_meta( $post_id, '_igfs_url', current( $urls ) );
	}
}
add_action( 'save_post_' . IGFS_CPT, 'igfs_meta_save' );

/* -------------------------------------------------------------------------
 * Contas: a principal (Conta do Instagram) e as "Outras contas".
 * O estado de cada conta (última publicação, perfil) fica em opções próprias;
 * as da principal mantêm os nomes antigos, por compatibilidade.
 * ---------------------------------------------------------------------- */

/**
 * Contas configuradas, [ conta => data inicial ]. A primeira é a principal.
 *
 * @return array<string,string>
 */
function igfs_accounts() {
	$accounts = array();
	$main     = (string) get_option( 'igfs_username', '' );
	if ( '' !== $main ) {
		$accounts[ $main ] = (string) get_option( 'igfs_start_date', '' );
	}
	foreach ( igfs_parse_accounts( (string) get_option( 'igfs_other_accounts', '' ) ) as $name => $date ) {
		if ( ! isset( $accounts[ $name ] ) ) {
			$accounts[ $name ] = $date;
		}
	}
	return $accounts;
}

/**
 * Lê "conta AAAA-MM-DD", uma por linha. Linhas inválidas são ignoradas.
 *
 * @param string $raw Texto.
 * @return array<string,string>
 */
function igfs_parse_accounts( $raw ) {
	$accounts = array();
	foreach ( preg_split( '/\R/', $raw ) as $line ) {
		if ( preg_match( '/^\s*@?([A-Za-z0-9._]{1,30})\s+(\d{4}-\d{2}-\d{2})\s*$/', $line, $m ) ) {
			$accounts[ $m[1] ] = $m[2];
		}
	}
	return $accounts;
}

/**
 * Conta pedida, ou a principal quando vazia. Devolve '' se não estiver configurada.
 *
 * @param string $requested Nome da conta.
 * @return string
 */
function igfs_account( $requested ) {
	$accounts = igfs_accounts();
	if ( '' === (string) $requested ) {
		return $accounts ? (string) array_key_first( $accounts ) : '';
	}
	return isset( $accounts[ $requested ] ) ? (string) $requested : '';
}

/**
 * Nome da opção de uma conta (a principal usa o nome antigo).
 *
 * @param string $name    Opção base (ex.: igfs_last_post_at).
 * @param string $account Conta.
 * @return string
 */
function igfs_opt( $name, $account ) {
	return (string) get_option( 'igfs_username', '' ) === $account ? $name : $name . '__' . strtolower( $account );
}

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
			'args'                => array(
				'account' => array(
					'type'    => 'string',
					'default' => '',
				),
			),
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
				'account'   => array(
					'type'    => 'string',
					'default' => '',
				),
				'images'    => array(
					'type'     => 'array',
					'default'  => array(),
					'maxItems' => 20,
					'items'    => array( 'type' => 'string' ),
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
				'error'       => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'igfs_register_routes' );

/**
 * Estado para o n8n: conta a seguir, desde quando pedir e se está ativo.
 * Regista a hora do pedido. Parâmetro opcional ?account= (vazio = conta principal).
 *
 * @param WP_REST_Request $request Pedido.
 * @return WP_REST_Response|WP_Error
 */
function igfs_rest_state( WP_REST_Request $request ) {
	$username = igfs_account( (string) $request['account'] );
	if ( '' === $username && '' !== (string) $request['account'] ) {
		return new WP_Error( 'igfs_account', 'Conta não configurada neste site.', array( 'status' => 404 ) );
	}

	$now = time();
	update_option( 'igfs_last_sync_at', $now, false );

	$accounts  = igfs_accounts();
	$last_post = '' !== $username ? (int) get_option( igfs_opt( 'igfs_last_post_at', $username ), 0 ) : 0;
	// Pede desde o dia da última publicação guardada (não da hora do pedido): se uma
	// execução falhar a meio, nada fica por importar. Os repetidos são ignorados.
	$since = $last_post ? gmdate( 'Y-m-d', $last_post ) : (string) ( $accounts[ $username ] ?? '' );

	// Publicações extra (ex.: colaborações de outra conta) ainda não guardadas. Só na conta principal.
	$extra = array();
	if ( '' !== $username && array_key_first( $accounts ) === $username ) {
		foreach ( igfs_extra_urls() as $shortcode => $url ) {
			if ( ! igfs_find_post( $shortcode ) ) {
				$extra[] = $url;
			}
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
	$ts        = min( strtotime( $request['timestamp'] ), time() ); // Data no futuro ficaria "agendada".
	$title     = igfs_title_from_caption( $caption, $shortcode );

	$account = igfs_account( (string) $request['account'] );
	if ( '' === $account ) {
		return new WP_Error( 'igfs_account', 'Conta não configurada neste site.', array( 'status' => 400 ) );
	}

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
		if ( count( (array) $request['images'] ) > 1 && ! get_post_meta( $post_id, '_igfs_images', true ) ) {
			igfs_save_gallery( $post_id, $request['images'], $shortcode, $title );
		}
		if ( $bump ) {
			igfs_bump_last_post( $ts, $account );
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
				'_igfs_account'   => $account,
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
	igfs_save_gallery( $post_id, $request['images'], $shortcode, $title );

	if ( $bump ) {
		igfs_bump_last_post( $ts, $account );
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
			'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' ),
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
 * @param int    $ts      Timestamp da publicação.
 * @param string $account Conta.
 */
function igfs_bump_last_post( $ts, $account ) {
	$option = igfs_opt( 'igfs_last_post_at', $account );
	if ( $ts > (int) get_option( $option, 0 ) ) {
		update_option( $option, $ts, false );
	}
}

/**
 * Guarda os dados do perfil. A foto só é descarregada de 7 em 7 dias
 * (o URL do Instagram muda a cada pedido).
 *
 * @param WP_REST_Request $request Pedido.
 * @return WP_REST_Response|WP_Error
 */
function igfs_rest_save_profile( WP_REST_Request $request ) {
	$account = igfs_account( (string) $request['username'] );
	if ( '' === $account ) {
		return new WP_Error( 'igfs_account', 'Conta não configurada neste site.', array( 'status' => 400 ) );
	}
	// Erro do Instagram (conta inexistente, privada, com restrição de idade): fica registado
	// e aparece nas definições e no painel; o perfil guardado não é alterado.
	update_option(
		igfs_opt( 'igfs_status', $account ),
		array(
			'at'    => time(),
			'error' => (string) $request['error'],
		),
		false
	);
	if ( '' !== (string) $request['error'] ) {
		return rest_ensure_response( array( 'status' => 'error_saved' ) );
	}

	$option  = igfs_opt( 'igfs_profile', $account );
	$old     = (array) get_option( $option, array() );
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

	update_option( $option, $profile, false );
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

	list( $tmp, $ext ) = igfs_optimize_image( $tmp, $exts[ $mime ] );

	// Só os tamanhos que a grelha e o admin usam (o tema regista mais de 10).
	add_filter( 'intermediate_image_sizes_advanced', 'igfs_needed_sizes' );
	add_filter( 'big_image_size_threshold', '__return_false' );
	$id = media_handle_sideload(
		array(
			'name'     => sanitize_file_name( $name ) . '.' . $ext,
			'tmp_name' => $tmp,
		),
		$post_id,
		$desc
	);
	remove_filter( 'intermediate_image_sizes_advanced', 'igfs_needed_sizes' );
	remove_filter( 'big_image_size_threshold', '__return_false' );
	if ( is_wp_error( $id ) ) {
		wp_delete_file( $tmp );
	}
	return $id;
}

/**
 * Reduz a imagem (máx. 1080 px, a resolução do Instagram) e converte para WebP (ou JPEG
 * se o servidor não suportar WebP), qualidade 80. Se falhar, fica a imagem original.
 *
 * @param string $file Ficheiro temporário.
 * @param string $ext  Extensão original.
 * @return array{0:string,1:string} Ficheiro e extensão.
 */
function igfs_optimize_image( $file, $ext ) {
	$editor = wp_get_image_editor( $file );
	if ( is_wp_error( $editor ) ) {
		return array( $file, $ext );
	}
	$max = (int) apply_filters( 'igfs_image_max', igfs_image_setting( 'igfs_image_max' ) );
	$editor->resize( $max, $max, false ); // Não aumenta imagens mais pequenas.
	$editor->set_quality( (int) apply_filters( 'igfs_image_quality', igfs_image_setting( 'igfs_image_quality' ) ) );
	$webp  = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
	$new   = $webp ? 'webp' : 'jpg';
	$saved = $editor->save( $file . '-igfs.' . $new, $webp ? 'image/webp' : 'image/jpeg' );
	if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
		return array( $file, $ext );
	}
	wp_delete_file( $file );
	return array( $saved['path'], $new );
}

/**
 * Tamanho máximo e qualidade das imagens (definições), dentro de limites seguros.
 *
 * @param string     $option igfs_image_max ou igfs_image_quality.
 * @param mixed|null $value  Valor a validar (por defeito, o guardado).
 * @return int
 */
function igfs_image_setting( $option, $value = null ) {
	$limits = array(
		'igfs_image_max'     => array( 1080, 480, 2160 ),
		'igfs_image_quality' => array( 80, 50, 95 ),
	);
	list( $default, $min, $max ) = $limits[ $option ];
	$value = null === $value ? get_option( $option, $default ) : $value;
	return min( max( (int) $value ? (int) $value : $default, $min ), $max );
}

/**
 * Tamanhos gerados nas imagens do feed.
 *
 * @param array $sizes Tamanhos registados.
 * @return array
 */
function igfs_needed_sizes( $sizes ) {
	return array_intersect_key( $sizes, array_flip( array( 'thumbnail', 'medium_large' ) ) );
}

/**
 * Descarrega as imagens extra de um carrossel (a 1.ª é a capa, já guardada).
 *
 * @param int    $post_id   Publicação.
 * @param array  $urls      URLs das imagens do carrossel.
 * @param string $shortcode Shortcode do Instagram.
 * @param string $title     Título.
 */
function igfs_save_gallery( $post_id, $urls, $shortcode, $title ) {
	$ids = array();
	foreach ( array_slice( array_values( (array) $urls ), 1, 9 ) as $i => $url ) {
		$id = igfs_sideload( (string) $url, $post_id, 'ig-' . $shortcode . '-' . ( $i + 2 ), $title );
		if ( ! is_wp_error( $id ) ) { // Uma imagem extra que falhe não impede a publicação.
			$ids[] = (int) $id;
		}
	}
	if ( $ids ) {
		update_post_meta( $post_id, '_igfs_images', $ids );
	}
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
	wp_register_script(
		'igfs-lightbox',
		plugins_url( 'assets/lightbox.js', __FILE__ ),
		array(),
		(string) filemtime( __DIR__ . '/assets/lightbox.js' ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
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
			'button'   => null, // null = texto das definições; button="" esconde o botão.
			'featured' => '',
			'ratio'    => '',
			'width'    => '',
			'account'  => '',
			'lightbox' => null, // null = definição do plugin; lightbox="1" ou "0" sobrepõe.
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

	$account = igfs_account( ltrim( (string) $atts['account'], '@' ) );
	if ( '' === $account ) {
		return '';
	}
	$is_main = array_key_first( igfs_accounts() ) === $account;
	// Publicações antigas (antes de haver várias contas) não têm conta: são da principal.
	$by_account = array(
		'key'   => '_igfs_account',
		'value' => $account,
	);
	$meta_query = $is_main ? array(
		'relation' => 'OR',
		$by_account,
		array(
			'key'     => '_igfs_account',
			'compare' => 'NOT EXISTS',
		),
	) : array( $by_account );

	$query = new WP_Query(
		array(
			'post_type'              => IGFS_CPT,
			'post_status'            => 'publish',
			'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'posts_per_page'         => min( max( absint( $atts['num'] ), 1 ), 50 ),
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		)
	);
	if ( ! $query->have_posts() ) {
		return '';
	}

	wp_enqueue_style( 'igfs-feed' );
	$lightbox = null === $atts['lightbox'] ? (bool) get_option( 'igfs_lightbox', 1 ) : '0' !== (string) $atts['lightbox'];
	if ( $lightbox ) {
		wp_enqueue_script( 'igfs-lightbox' );
	}
	$items = array(); // Dados de cada publicação para a lightbox.

	$profile  = (array) get_option( igfs_opt( 'igfs_profile', $account ), array() );
	$username = ! empty( $profile['username'] ) ? $profile['username'] : $account;
	$title    = '' !== $atts['title'] ? $atts['title'] : '@' . $username;
	$avatar   = $is_main ? (int) get_option( 'igfs_avatar_id', 0 ) : 0; // A imagem escolhida nas definições é da principal.
	if ( ! $avatar && ! empty( $profile['avatar_id'] ) ) {
		$avatar = (int) $profile['avatar_id'];
	}
	$prof_url = 'https://www.instagram.com/' . rawurlencode( $username ) . '/';
	$icons    = array(
		'Sidecar' => '<svg class="igfs__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h11a3 3 0 0 1 3 3v11h-2V6a1 1 0 0 0-1-1H7z"/><path d="M5 7h10a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2zm0 2v10h10V9z"/></svg>',
		'Video'   => '<svg class="igfs__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4.5v15L19.5 12z"/></svg>',
	);

	// Botão: texto e aspeto das definições (o atributo button do shortcode sobrepõe o texto).
	$btn_text  = null === $atts['button'] ? (string) get_option( 'igfs_btn_text', '' ) : (string) $atts['button'];
	$btn_vars  = '';
	$btn_class = '';
	foreach ( array( 'igfs_btn_bg' => '--igfs-accent', 'igfs_btn_color' => '--igfs-accent-text' ) as $opt => $var ) {
		$color = sanitize_hex_color( (string) get_option( $opt, '' ) );
		$btn_vars .= $color ? ';' . $var . ':' . $color : '';
	}
	$btn_vars  .= ';--igfs-btn-radius:' . absint( get_option( 'igfs_btn_radius', 0 ) ) . 'px';
	$btn_class .= 'outline' === get_option( 'igfs_btn_style' ) ? ' igfs--btn-outline' : '';
	$btn_class .= get_option( 'igfs_btn_upper' ) ? ' igfs--btn-upper' : '';

	ob_start();
	?>
	<div class="igfs igfs--<?php echo esc_attr( $layout ); ?><?php echo $featured ? ' igfs--featured' : ''; ?><?php echo $tall ? ' igfs--featured-tall' : ''; ?><?php echo esc_attr( $btn_class ); ?>" style="--igfs-cols:<?php echo esc_attr( $cols ); ?>;--igfs-ratio:<?php echo esc_attr( $ratio ); ?>;--igfs-rest:<?php echo esc_attr( max( $cols - 1, 1 ) ); ?><?php echo absint( $atts['width'] ) ? ';--igfs-max-width:' . absint( $atts['width'] ) . 'px' : ''; ?><?php echo esc_attr( $btn_vars ); ?>">
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
				if ( $lightbox ) {
					$slides = array();
					foreach ( array_merge( array( (int) get_post_thumbnail_id() ), array_map( 'intval', (array) get_post_meta( get_the_ID(), '_igfs_images', true ) ) ) as $att ) {
						$src = $att ? wp_get_attachment_image_src( $att, 'large' ) : false; // 'large' não existe nas novas: devolve a original (1080 px).
						if ( $src ) {
							$slides[] = array( $src[0], (int) $src[1], (int) $src[2] );
						}
					}
					// Bases de dados utf8 (sem mb4) guardam os emojis como &#x…; — volta a convertê-los.
					$caption = html_entity_decode( get_the_content(), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					$items[] = array(
						'url'     => (string) get_post_meta( get_the_ID(), '_igfs_url', true ),
						'sc'      => (string) get_post_meta( get_the_ID(), '_igfs_shortcode', true ),
						'type'    => $type,
						'caption' => $caption,
						'date'    => get_the_date(),
						'slides'  => $slides,
					);
				}
				?>
				<li class="igfs__item">
					<?php if ( 'cards' === $layout ) : ?>
						<div class="igfs__meta">
							<?php // Só a data: a foto e o nome já estão no cabeçalho da secção. ?>
							<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date( 'M j' ) ); ?></time>
						</div>
					<?php endif; ?>
					<a class="igfs__media" href="<?php echo esc_url( get_post_meta( get_the_ID(), '_igfs_url', true ) ); ?>" target="_blank" rel="noopener"<?php echo $lightbox ? ' data-igfs-i="' . esc_attr( count( $items ) - 1 ) . '"' : ''; ?>>
						<?php
						echo get_the_post_thumbnail(
							null,
							'medium_large',
							array(
								'alt'     => wp_trim_words( html_entity_decode( get_the_content(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 20 ),
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
		<?php if ( $lightbox ) : ?>
			<?php // <template> e não <script>: bloqueadores de cookies (ex.: Complianz) reescrevem scripts que mencionam o Instagram e estragavam o JSON. ?>
			<template class="igfs-data">
			<?php
			echo wp_json_encode(
				array(
					'user'   => $username,
					'avatar' => $avatar ? (string) wp_get_attachment_image_url( $avatar, 'thumbnail' ) : '',
					'items'  => $items,
					'linkPhotos' => (bool) get_option( 'igfs_lb_link_photos', 0 ),
					'i18n'   => array(
						'close'   => __( 'Fechar', 'ig-feed-sync' ),
						'prev'    => __( 'Publicação anterior', 'ig-feed-sync' ),
						'next'    => __( 'Publicação seguinte', 'ig-feed-sync' ),
						'slide'   => __( 'Imagem', 'ig-feed-sync' ),
						'open'    => __( 'Ver no Instagram', 'ig-feed-sync' ),
						'consent' => __( 'Para ver o vídeo aqui, aceite os cookies de marketing.', 'ig-feed-sync' ),
						'video'   => __( 'Se o vídeo não abrir, veja-o no Instagram.', 'ig-feed-sync' ),
						'label'   => __( 'Publicação do Instagram', 'ig-feed-sync' ),
						'post'    => __( 'Publicação', 'ig-feed-sync' ),
					),
				),
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
			);
			?>
			</template>
		<?php endif; ?>
		<?php if ( '' !== $btn_text ) : ?>
			<a class="igfs__button" href="<?php echo esc_url( $prof_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $btn_text ); ?></a>
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
		'igfs_other_accounts',
		array(
			'sanitize_callback' => function ( $value ) {
				$lines = array();
				foreach ( igfs_parse_accounts( (string) $value ) as $name => $date ) {
					$lines[] = $name . ' ' . $date;
				}
				return implode( "\n", $lines );
			},
		)
	);
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
		'igfs_lb_link_photos',
		array(
			'sanitize_callback' => function ( $value ) {
				return $value ? 1 : 0;
			},
		)
	);
	register_setting(
		'igfs',
		'igfs_lightbox',
		array(
			'sanitize_callback' => function ( $value ) {
				return $value ? 1 : 0;
			},
		)
	);
	foreach ( array( 'igfs_image_max', 'igfs_image_quality' ) as $image_option ) {
		register_setting(
			'igfs',
			$image_option,
			array(
				'sanitize_callback' => function ( $value ) use ( $image_option ) {
					return igfs_image_setting( $image_option, $value );
				},
			)
		);
	}
	register_setting( 'igfs', 'igfs_btn_text', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	register_setting( 'igfs', 'igfs_btn_bg', array( 'sanitize_callback' => 'sanitize_hex_color' ) );
	register_setting( 'igfs', 'igfs_btn_color', array( 'sanitize_callback' => 'sanitize_hex_color' ) );
	register_setting( 'igfs', 'igfs_btn_radius', array( 'sanitize_callback' => 'absint' ) );
	register_setting(
		'igfs',
		'igfs_btn_style',
		array(
			'sanitize_callback' => function ( $value ) {
				return 'outline' === $value ? 'outline' : 'fill';
			},
		)
	);
	register_setting(
		'igfs',
		'igfs_btn_upper',
		array(
			'sanitize_callback' => function ( $value ) {
				return $value ? 1 : 0;
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
 * Data "Importar desde" alterada: a próxima execução volta a pedir a partir dela.
 * As publicações já guardadas são reconhecidas pelo shortcode e não se duplicam.
 */
function igfs_start_date_changed() {
	delete_option( 'igfs_last_post_at' );
}
add_action( 'update_option_igfs_start_date', 'igfs_start_date_changed' );

/**
 * Conta principal alterada: a data e o perfil eram da conta antiga.
 * A foto antiga é apagada e a próxima execução importa a conta nova desde a data inicial.
 */
function igfs_username_changed() {
	$profile = (array) get_option( 'igfs_profile', array() );
	if ( ! empty( $profile['avatar_id'] ) ) {
		wp_delete_attachment( (int) $profile['avatar_id'], true );
	}
	delete_option( 'igfs_profile' );
	delete_option( 'igfs_last_post_at' );
	delete_option( 'igfs_status' );
}
add_action( 'update_option_igfs_username', 'igfs_username_changed' );

/**
 * Outras contas: quando a data de uma conta muda, a próxima execução volta a pedir a partir dela.
 *
 * @param string $old Valor antigo.
 * @param string $new Valor novo.
 */
function igfs_other_accounts_changed( $old, $new ) {
	$before = igfs_parse_accounts( (string) $old );
	foreach ( igfs_parse_accounts( (string) $new ) as $name => $date ) {
		if ( ( $before[ $name ] ?? '' ) !== $date ) {
			delete_option( igfs_opt( 'igfs_last_post_at', $name ) );
		}
	}
}
add_action( 'update_option_igfs_other_accounts', 'igfs_other_accounts_changed', 10, 2 );

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
	wp_enqueue_style( 'wp-color-picker' );
	wp_enqueue_script( 'wp-color-picker' );
	wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){$(".igfs-color").wpColorPicker();});' );
	wp_register_script( 'igfs-admin', false, array( 'media-editor' ), IGFS_VERSION, true );
	wp_enqueue_script( 'igfs-admin' );
	wp_add_inline_script(
		'igfs-admin',
		"document.addEventListener('click',function(e){var b=e.target.closest('[data-igfs-avatar]');if(!b){return;}e.preventDefault();var input=document.getElementById('igfs_avatar_id'),prev=document.getElementById('igfs-avatar-preview');if('remove'===b.dataset.igfsAvatar){input.value='';prev.textContent='';return;}var frame=wp.media({title:b.textContent,library:{type:'image'},multiple:false});frame.on('select',function(){var a=frame.state().get('selection').first().toJSON(),img=document.createElement('img');input.value=a.id;img.src=(a.sizes&&a.sizes.thumbnail?a.sizes.thumbnail:a).url;img.width=80;img.height=80;img.style.cssText='border-radius:50%;object-fit:cover';prev.textContent='';prev.appendChild(img);});frame.open();});"
	);
}
add_action( 'admin_enqueue_scripts', 'igfs_admin_assets' );

/**
 * Aviso no painel quando o Instagram devolveu um erro para uma conta.
 */
function igfs_admin_notices() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	foreach ( array_keys( igfs_accounts() ) as $name ) {
		$status = (array) get_option( igfs_opt( 'igfs_status', $name ), array() );
		if ( empty( $status['error'] ) ) {
			continue;
		}
		printf(
			'<div class="notice notice-error"><p><strong>IG Feed Sync:</strong> %1$s <code>%2$s</code>: %3$s <a href="%4$s">%5$s</a></p></div>',
			esc_html__( 'o Instagram não devolveu as publicações da conta', 'ig-feed-sync' ),
			esc_html( $name ),
			esc_html( $status['error'] ),
			esc_url( admin_url( 'options-general.php?page=igfs' ) ),
			esc_html__( 'Ver definições', 'ig-feed-sync' )
		);
	}
}
add_action( 'admin_notices', 'igfs_admin_notices' );

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
	$count     = wp_count_posts( IGFS_CPT );
	?>
	<div class="wrap">
		<h1>IG Feed Sync</h1>
		<?php if ( isset( $_GET['key'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Chave nova gerada. Atualize a chave na lista Clientes do n8n.', 'ig-feed-sync' ); ?></p></div>
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
					<p class="description"><?php esc_html_e( 'Ao mudar esta data, a próxima execução do n8n volta a pedir a partir dela (sem duplicar o que já existe). Depois, os pedidos começam no dia da última publicação guardada.', 'ig-feed-sync' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="igfs_other_accounts"><?php esc_html_e( 'Outras contas', 'ig-feed-sync' ); ?></label></th>
					<td><textarea name="igfs_other_accounts" id="igfs_other_accounts" rows="3" class="large-text code" placeholder="outraconta 2026-06-01"><?php echo esc_textarea( get_option( 'igfs_other_accounts', '' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Opcional. Uma conta por linha: o nome e a data de início (AAAA-MM-DD). Cada conta tem a sua grelha: [igfs_feed account="outraconta"]. No n8n, acrescentar uma linha por conta na lista Clientes, com account.', 'ig-feed-sync' ); ?></p></td>
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
					<th scope="row" colspan="2"><h2 style="margin:0"><?php esc_html_e( 'Imagens', 'ig-feed-sync' ); ?></h2></th>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Lightbox', 'ig-feed-sync' ); ?></th>
					<td><label><input name="igfs_lightbox" type="checkbox" value="1" <?php checked( get_option( 'igfs_lightbox', 1 ) ); ?>> <?php esc_html_e( 'Abrir as publicações numa janela no site', 'ig-feed-sync' ); ?></label>
					<p class="description"><?php esc_html_e( 'Desligada, cada publicação abre no Instagram, num separador novo. No shortcode, lightbox="1" ou lightbox="0" muda só essa grelha.', 'ig-feed-sync' ); ?></p>
					<label><input name="igfs_lb_link_photos" type="checkbox" value="1" <?php checked( get_option( 'igfs_lb_link_photos', 0 ) ); ?>> <?php esc_html_e( 'Mostrar o botão "Ver no Instagram" também nas fotos (nos vídeos aparece sempre)', 'ig-feed-sync' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="igfs_image_max"><?php esc_html_e( 'Tamanho máximo', 'ig-feed-sync' ); ?></label></th>
					<td><input name="igfs_image_max" id="igfs_image_max" type="number" min="480" max="2160" step="10" class="small-text" value="<?php echo esc_attr( igfs_image_setting( 'igfs_image_max' ) ); ?>"> px
					<p class="description"><?php esc_html_e( 'Lado maior das imagens guardadas (480 a 2160). 1080 é a resolução do Instagram: acima disso a imagem não ganha qualidade, só peso.', 'ig-feed-sync' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="igfs_image_quality"><?php esc_html_e( 'Qualidade', 'ig-feed-sync' ); ?></label></th>
					<td><input name="igfs_image_quality" id="igfs_image_quality" type="number" min="50" max="95" class="small-text" value="<?php echo esc_attr( igfs_image_setting( 'igfs_image_quality' ) ); ?>">
					<p class="description"><?php esc_html_e( 'Compressão WebP de 50 a 95 (80 recomendado). Mais alto = imagens mais nítidas e mais pesadas. Aplica-se às imagens importadas a partir daqui.', 'ig-feed-sync' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row" colspan="2"><h2 style="margin:0"><?php esc_html_e( 'Botão "Ver no Instagram"', 'ig-feed-sync' ); ?></h2></th>
				</tr>
				<tr>
					<th scope="row"><label for="igfs_btn_text"><?php esc_html_e( 'Texto', 'ig-feed-sync' ); ?></label></th>
					<td><input name="igfs_btn_text" id="igfs_btn_text" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'igfs_btn_text', '' ) ); ?>" placeholder="Siga-nos no Instagram">
					<p class="description"><?php esc_html_e( 'Aparece por baixo de todas as grelhas e leva ao perfil da conta. Vazio = sem botão. No shortcode, button="..." muda o texto e button="" esconde o botão.', 'ig-feed-sync' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Cores', 'ig-feed-sync' ); ?></th>
					<td>
						<label><?php esc_html_e( 'Fundo', 'ig-feed-sync' ); ?><br><input name="igfs_btn_bg" type="text" class="igfs-color" value="<?php echo esc_attr( get_option( 'igfs_btn_bg', '#111111' ) ); ?>" data-default-color="#111111"></label><br>
						<label><?php esc_html_e( 'Texto', 'ig-feed-sync' ); ?><br><input name="igfs_btn_color" type="text" class="igfs-color" value="<?php echo esc_attr( get_option( 'igfs_btn_color', '#ffffff' ) ); ?>" data-default-color="#ffffff"></label>
						<p class="description"><?php esc_html_e( 'A cor de fundo também é usada no ícone do cabeçalho do layout highlight.', 'ig-feed-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Estilo', 'ig-feed-sync' ); ?></th>
					<td>
						<label><input type="radio" name="igfs_btn_style" value="fill" <?php checked( 'outline' !== get_option( 'igfs_btn_style' ) ); ?>> <?php esc_html_e( 'Cheio', 'ig-feed-sync' ); ?></label>&nbsp;&nbsp;
						<label><input type="radio" name="igfs_btn_style" value="outline" <?php checked( 'outline', get_option( 'igfs_btn_style' ) ); ?>> <?php esc_html_e( 'Só contorno', 'ig-feed-sync' ); ?></label><br>
						<label><?php esc_html_e( 'Cantos arredondados', 'ig-feed-sync' ); ?> <input name="igfs_btn_radius" type="number" min="0" max="60" class="small-text" value="<?php echo esc_attr( (int) get_option( 'igfs_btn_radius', 0 ) ); ?>"> px</label><br>
						<label><input name="igfs_btn_upper" type="checkbox" value="1" <?php checked( get_option( 'igfs_btn_upper' ) ); ?>> <?php esc_html_e( 'Texto em maiúsculas', 'ig-feed-sync' ); ?></label>
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
				<th scope="row"><?php esc_html_e( 'Chave do site (para o n8n)', 'ig-feed-sync' ); ?></th>
				<td><input type="text" class="large-text code" readonly onfocus="this.select()" value="<?php echo esc_attr( get_option( 'igfs_key', '' ) ); ?>">
				<p class="description"><?php esc_html_e( 'Colar no campo key da lista Clientes do n8n. É a mesma para todas as contas deste site.', 'ig-feed-sync' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Último pedido do n8n', 'ig-feed-sync' ); ?></th>
				<td><?php echo $last_sync ? esc_html( wp_date( $fmt, $last_sync ) ) : '—'; ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Contas', 'ig-feed-sync' ); ?></th>
				<td>
					<?php foreach ( array_keys( igfs_accounts() ) as $name ) : ?>
						<?php
						$last    = (int) get_option( igfs_opt( 'igfs_last_post_at', $name ), 0 );
						$prof    = (array) get_option( igfs_opt( 'igfs_profile', $name ), array() );
						$status  = (array) get_option( igfs_opt( 'igfs_status', $name ), array() );
						?>
						<div style="display:flex;gap:12px;align-items:center;margin-bottom:12px">
							<?php echo ! empty( $prof['avatar_id'] ) ? wp_get_attachment_image( (int) $prof['avatar_id'], array( 48, 48 ), false, array( 'style' => 'border-radius:50%;width:48px;height:48px;object-fit:cover' ) ) : '<span style="display:inline-block;width:48px;height:48px;border-radius:50%;background:#ddd"></span>'; ?>
							<div>
								<strong><?php echo esc_html( $name ); ?></strong>
								<?php if ( ! empty( $prof['full_name'] ) ) : ?>
									— <?php echo esc_html( $prof['full_name'] ); ?>, <?php echo esc_html( number_format_i18n( (int) $prof['followers'] ) ); ?> <?php esc_html_e( 'seguidores', 'ig-feed-sync' ); ?>
								<?php endif; ?>
								<br>
								<?php if ( ! empty( $status['error'] ) ) : ?>
									<span style="color:#b32d2e">✗ <?php echo esc_html( $status['error'] ); ?> (<?php echo esc_html( wp_date( $fmt, (int) $status['at'] ) ); ?>)</span>
								<?php elseif ( ! empty( $status['at'] ) ) : ?>
									<span style="color:#007017">✓ <?php esc_html_e( 'Perfil lido em', 'ig-feed-sync' ); ?> <?php echo esc_html( wp_date( $fmt, (int) $status['at'] ) ); ?></span>
								<?php else : ?>
									<span><?php esc_html_e( 'Ainda não sincronizada.', 'ig-feed-sync' ); ?></span>
								<?php endif; ?>
								· <?php esc_html_e( 'Publicação mais recente:', 'ig-feed-sync' ); ?> <?php echo $last ? esc_html( wp_date( $fmt, $last ) ) : '—'; ?>
							</div>
						</div>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Confirme que o nome e a foto são da conta certa: um nome mal escrito pode corresponder a outra conta do Instagram.', 'ig-feed-sync' ); ?></p>
					(<?php echo esc_html( (int) $count->publish ); ?> <?php esc_html_e( 'publicadas no total', 'ig-feed-sync' ); ?>)
				</td>
			</tr>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'A chave atual deixa de funcionar. Continuar?', 'ig-feed-sync' ) ); ?>');">
			<input type="hidden" name="action" value="igfs_regenerate_key">
			<?php wp_nonce_field( 'igfs_regenerate_key' ); ?>
			<?php submit_button( __( 'Gerar chave nova', 'ig-feed-sync' ), 'secondary', 'submit', false ); ?>
		</form>
		<h2><?php esc_html_e( 'Shortcodes', 'ig-feed-sync' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php foreach ( array_keys( igfs_accounts() ) as $i => $name ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $name ); ?><?php echo 0 === $i ? ' (' . esc_html__( 'principal', 'ig-feed-sync' ) . ')' : ''; ?></th>
					<td>
						<code>[igfs_feed<?php echo 0 === $i ? '' : ' account="' . esc_html( $name ) . '"'; ?> layout="cards" num="7" featured="tall"]</code><br>
						<code>[igfs_feed<?php echo 0 === $i ? '' : ' account="' . esc_html( $name ) . '"'; ?> layout="highlight" num="9" title="@<?php echo esc_html( $name ); ?>" button="Ver mais no Instagram"]</code>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<p class="description"><?php esc_html_e( 'Opções: layout="cards|highlight", num (número de publicações), cols (colunas), featured="1|tall|0" (1.ª em 2x2, em 1x2 ou normal), ratio="1|4/5|auto", width="1100" (largura máxima em px), title, button.', 'ig-feed-sync' ); ?></p>
	</div>
	<?php
}
