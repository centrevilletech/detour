<?php
/**
 * Detour - per-URL rule engine.
 *
 * Three match types (exact, prefix, regex) and five status codes
 * (301, 302, 307, 308, 410). Lookups go through the WordPress object
 * cache with a versioned bust on every rule write.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DETOUR_RULES_VERSION     = '2';
const DETOUR_RULES_CACHE_GROUP = 'detour_rules';

function detour_rules_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'detour_rules';
}

/**
 * Idempotent schema bootstrap. Called from the activation hook
 * and once per page load via plugins_loaded; short-circuits cheaply
 * via the schema-version option once applied.
 */
function detour_create_rules_table() {
	if ( get_option( 'centct_p404_rules_schema_version' ) === DETOUR_RULES_VERSION ) {
		return;
	}

	global $wpdb;
	$table   = detour_rules_table_name();
	$charset = $wpdb->get_charset_collate();

	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $exists !== $table ) {
		$wpdb->query(
			"CREATE TABLE `{$table}` (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				match_type ENUM('exact','prefix','regex') NOT NULL DEFAULT 'exact',
				source_pattern TEXT NOT NULL,
				source_hash BINARY(16) GENERATED ALWAYS AS (UNHEX(MD5(source_pattern))) STORED,
				destination_url TEXT NULL,
				redirect_status SMALLINT UNSIGNED NOT NULL DEFAULT 301,
				is_active TINYINT(1) NOT NULL DEFAULT 1,
				match_live TINYINT(1) NOT NULL DEFAULT 0,
				hit_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
				last_hit_at DATETIME NULL,
				notes VARCHAR(255) NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY idx_source_hash (source_hash),
				KEY idx_active_match (is_active, match_type),
				KEY idx_hit_count (hit_count)
			) {$charset}"
		);
	} else {
		// Schema 2 adds match_live: a prefix or regex rule that also fires
		// when the requested URL still resolves.
		$has_live = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table,
				'match_live'
			)
		);
		if ( ! $has_live ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN match_live TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active" );
		}
	}

	update_option( 'centct_p404_rules_schema_version', DETOUR_RULES_VERSION, false );
}

// ============================================================
// Cache versioning. Each write bumps the version so the next
// read repopulates fresh; no manual key invalidation needed.
// ============================================================

function detour_rules_cache_version() {
	$v = wp_cache_get( 'rules_cache_v', DETOUR_RULES_CACHE_GROUP );
	if ( false === $v ) {
		$v = 1;
		wp_cache_set( 'rules_cache_v', $v, DETOUR_RULES_CACHE_GROUP );
	}
	return (int) $v;
}

function detour_rules_bump_cache() {
	$v = detour_rules_cache_version() + 1;
	wp_cache_set( 'rules_cache_v', $v, DETOUR_RULES_CACHE_GROUP );
}

// ============================================================
// Match engine
// ============================================================

/**
 * Build the lookup keys for a request URL. Rules may be stored as a full
 * URL (what the 404 log's "promote" button produces) or as a site-rooted
 * path (what the rule form and the CLI importer suggest), so both forms are
 * tried, each with and without the query string and the trailing slash.
 * Keys are ordered most specific first.
 *
 * @return array[] Each entry: array( 'key' => string, 'subject' => string,
 *                 'query_dropped' => bool ).
 */
function detour_match_candidates( $url ) {
	$parts  = wp_parse_url( $url );
	$path   = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
	$query  = isset( $parts['query'] ) ? $parts['query'] : '';
	$origin = isset( $parts['scheme'], $parts['host'] ) ? $parts['scheme'] . '://' . $parts['host'] : '';

	$paths = array( $path );
	if ( '/' !== $path ) {
		$paths[] = '/' === substr( $path, -1 ) ? rtrim( $path, '/' ) : $path . '/';
	}
	$lower = array_map( 'strtolower', $paths );
	$paths = array_values( array_unique( array_merge( $paths, $lower ) ) );

	$out = array();
	foreach ( array( true, false ) as $with_query ) {
		if ( $with_query && '' === $query ) {
			continue;
		}
		foreach ( $paths as $p ) {
			$subject = $with_query ? $p . '?' . $query : $p;
			foreach ( array( $origin, '' ) as $base ) {
				$out[] = array(
					'key'           => $base . $subject,
					'subject'       => $subject,
					'query_dropped' => '' !== $query && ! $with_query,
				);
			}
		}
	}
	return $out;
}

/**
 * Try to find a rule matching the given URL. Returns the rule row
 * (associative array) or null. Match priority: exact → longest prefix → regex.
 *
 * The returned row carries two extra keys used by detour_apply_rule():
 * `_subject` (the path the rule matched) and `_query` (the request query
 * string when the rule matched without it, so it can be carried over).
 * Pass $live when the URL still resolves (not a 404): exact rules apply as
 * usual, but prefix and regex rules apply only if they set match_live.
 */
function detour_match_rule( $url, $live = false ) {
	global $wpdb;
	$table      = detour_rules_table_name();
	$candidates = detour_match_candidates( $url );
	$query      = (string) wp_parse_url( $url, PHP_URL_QUERY );

	// 1. Exact match by hash key (indexed lookup over all candidate keys).
	$keys   = array_values( array_unique( wp_list_pluck( $candidates, 'key' ) ) );
	$holder = implode( ',', array_fill( 0, count( $keys ), 'UNHEX(MD5(%s))' ) );
	$rows   = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM `{$table}`
			 WHERE match_type = 'exact' AND is_active = 1
			   AND source_hash IN ({$holder})",
			$keys
		),
		ARRAY_A
	);
	if ( $rows ) {
		$by_key = array();
		foreach ( $rows as $row ) {
			$by_key[ $row['source_pattern'] ] = $row;
		}
		foreach ( $candidates as $c ) {
			if ( isset( $by_key[ $c['key'] ] ) ) {
				$rule             = $by_key[ $c['key'] ];
				$rule['_subject'] = $c['subject'];
				$rule['_query']   = $c['query_dropped'] ? $query : '';
				return $rule;
			}
		}
	}

	// Prefix and regex rules see the site-rooted path (with query string)
	// first, then the full URL, so patterns written either way work.
	$parts    = wp_parse_url( $url );
	$path     = ( isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/' )
		. ( '' !== $query ? '?' . $query : '' );
	$subjects = array_unique( array( $path, $url ) );

	// 2. Prefix match - longest-pattern wins.
	$v            = detour_rules_cache_version();
	$prefix_rules = wp_cache_get( "prefix_rules_v{$v}", DETOUR_RULES_CACHE_GROUP );
	if ( false === $prefix_rules ) {
		$prefix_rules = $wpdb->get_results(
			"SELECT * FROM `{$table}`
			 WHERE match_type = 'prefix' AND is_active = 1
			 ORDER BY CHAR_LENGTH(source_pattern) DESC",
			ARRAY_A
		);
		wp_cache_set( "prefix_rules_v{$v}", $prefix_rules, DETOUR_RULES_CACHE_GROUP, 300 );
	}
	foreach ( $prefix_rules as $r ) {
		if ( $live && empty( $r['match_live'] ) ) {
			continue;
		}
		foreach ( $subjects as $subject ) {
			if ( strpos( $subject, $r['source_pattern'] ) === 0 ) {
				$r['_subject'] = $subject;
				$r['_query']   = '';
				return $r;
			}
		}
	}

	// 3. Regex match - first match wins, evaluation order = id ASC.
	$regex_rules = wp_cache_get( "regex_rules_v{$v}", DETOUR_RULES_CACHE_GROUP );
	if ( false === $regex_rules ) {
		$regex_rules = $wpdb->get_results(
			"SELECT * FROM `{$table}`
			 WHERE match_type = 'regex' AND is_active = 1
			 ORDER BY id ASC",
			ARRAY_A
		);
		wp_cache_set( "regex_rules_v{$v}", $regex_rules, DETOUR_RULES_CACHE_GROUP, 300 );
	}
	foreach ( $regex_rules as $r ) {
		if ( $live && empty( $r['match_live'] ) ) {
			continue;
		}
		$pattern = detour_normalize_regex( $r['source_pattern'] );
		foreach ( $subjects as $subject ) {
			if ( @preg_match( $pattern, $subject ) === 1 ) {
				$r['_subject'] = $subject;
				$r['_query']   = '';
				return $r;
			}
		}
	}

	return null;
}

/**
 * Wrap a regex in `~...~` delimiters if the user did not supply any.
 * Strips line breaks defensively.
 */
function detour_normalize_regex( $pattern ) {
	$pattern = (string) $pattern;
	$pattern = str_replace( array( "\r", "\n" ), '', $pattern );
	if ( '' === $pattern ) {
		return '~$.^~'; // never matches
	}
	$first = $pattern[0];
	if ( ! in_array( $first, array( '/', '#', '~', '%' ), true ) ) {
		return '~' . $pattern . '~';
	}
	return $pattern;
}

/**
 * Best-effort hit counter; failures never block the response.
 */
function detour_count_rule_hit( $rule ) {
	global $wpdb;
	$wpdb->query(
		$wpdb->prepare(
			'UPDATE `' . detour_rules_table_name() . '` SET hit_count = hit_count + 1, last_hit_at = %s WHERE id = %d',
			current_time( 'mysql' ),
			(int) $rule['id']
		)
	);
}

/**
 * True when two URLs point at the same host, path and query, ignoring
 * scheme, a leading "www.", letter case and the trailing slash. A URL
 * without a host (a site-rooted path) counts as this site.
 */
function detour_is_same_url( $a, $b ) {
	$home = wp_parse_url( home_url(), PHP_URL_HOST );
	$norm = function ( $u ) use ( $home ) {
		$p    = wp_parse_url( $u );
		$host = strtolower( preg_replace( '/^www\./i', '', isset( $p['host'] ) ? $p['host'] : (string) $home ) );
		$path = isset( $p['path'] ) ? strtolower( untrailingslashit( $p['path'] ) ) : '';
		return $host . $path . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
	};
	return $norm( $a ) === $norm( $b );
}

/**
 * Apply a matched rule. For 410, emits Gone and exits. For redirects,
 * substitutes {path} for prefix-mode and emits a Location header. The
 * hit counter is updated best-effort; failures don't block the response.
 */
function detour_apply_rule( $rule, $url ) {
	global $wpdb;
	$table = detour_rules_table_name();

	$status = (int) $rule['redirect_status'];

	if ( 410 === $status ) {
		detour_count_rule_hit( $rule );
		status_header( 410 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><meta charset="utf-8"><title>410 Gone</title>'
			. '<h1>410 Gone</h1><p>This resource is no longer available.</p>';
		exit;
	}

	$destination = (string) $rule['destination_url'];
	if ( '' === $destination ) {
		return; // misconfigured; let caller fall through to global default
	}

	$subject = isset( $rule['_subject'] ) ? $rule['_subject'] : $url;

	// {path} substitution for prefix rules: append the unmatched tail.
	if ( 'prefix' === $rule['match_type'] && strpos( $destination, '{path}' ) !== false ) {
		$tail        = substr( $subject, strlen( $rule['source_pattern'] ) );
		$destination = str_replace( '{path}', $tail, $destination );
	}

	// $1-style backreferences for regex rules.
	if ( 'regex' === $rule['match_type'] && preg_match( '/\$\d/', $destination ) ) {
		$pattern = detour_normalize_regex( $rule['source_pattern'] );
		if ( preg_match( $pattern, $subject, $m ) === 1 ) {
			$destination = preg_replace_callback(
				'/\$(\d+)/',
				function ( $ref ) use ( $m ) {
					return isset( $m[ (int) $ref[1] ] ) ? $m[ (int) $ref[1] ] : '';
				},
				$destination
			);
		}
	}

	// A rule that matched without the query string passes it through, so
	// campaign tags (utm_*, gclid) survive the redirect.
	if ( ! empty( $rule['_query'] ) ) {
		$destination .= ( strpos( $destination, '?' ) === false ? '?' : '&' ) . $rule['_query'];
	}

	// Never redirect a URL to itself (e.g. `/page` -> `/page/` when the
	// request was already `/page/`). Returning lets the page render.
	if ( detour_is_same_url( $destination, $url ) ) {
		return;
	}

	detour_count_rule_hit( $rule );
	wp_redirect( $destination, $status );
	exit;
}

// ============================================================
// CRUD helpers
// ============================================================

function detour_get_rules( $args = array() ) {
	global $wpdb;
	$table = detour_rules_table_name();

	$defaults = array(
		'page'     => 1,
		'per_page' => 20,
		'orderby'  => 'id',
		'order'    => 'DESC',
		'search'   => '',
	);
	$args     = array_merge( $defaults, $args );

	$allowed = array( 'id', 'match_type', 'redirect_status', 'is_active', 'hit_count', 'last_hit_at', 'created_at', 'updated_at' );
	if ( ! in_array( $args['orderby'], $allowed, true ) ) {
		$args['orderby'] = 'id';
	}
	$args['order'] = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

	$where  = 'WHERE 1=1';
	$params = array();
	if ( '' !== $args['search'] ) {
		$where .= ' AND (source_pattern LIKE %s OR destination_url LIKE %s OR notes LIKE %s)';
		$like   = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		$params = array( $like, $like, $like );
	}

	$count_sql = "SELECT COUNT(*) FROM `{$table}` {$where}";
	$total     = (int) ( $params
		? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
		: $wpdb->get_var( $count_sql ) );

	$offset      = max( 0, ( (int) $args['page'] - 1 ) ) * (int) $args['per_page'];
	$list_sql    = "SELECT * FROM `{$table}` {$where} ORDER BY {$args['orderby']} {$args['order']} LIMIT %d OFFSET %d";
	$list_params = array_merge( $params, array( (int) $args['per_page'], (int) $offset ) );
	$rows        = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );

	return array(
		'rows'  => $rows,
		'total' => $total,
		'pages' => max( 1, (int) ceil( $total / max( 1, (int) $args['per_page'] ) ) ),
	);
}

function detour_get_rule( $id ) {
	global $wpdb;
	$table = detour_rules_table_name();
	return $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", (int) $id ),
		ARRAY_A
	);
}

function detour_create_rule( $data ) {
	global $wpdb;
	$clean = detour_sanitize_rule_input( $data );
	if ( is_wp_error( $clean ) ) {
		return $clean;
	}

	$ok = $wpdb->insert(
		detour_rules_table_name(),
		array(
			'match_type'      => $clean['match_type'],
			'source_pattern'  => $clean['source_pattern'],
			'destination_url' => $clean['destination_url'],
			'redirect_status' => $clean['redirect_status'],
			'is_active'       => $clean['is_active'],
			'match_live'      => $clean['match_live'],
			'notes'           => $clean['notes'],
		),
		array( '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
	);
	if ( ! $ok ) {
		return new WP_Error( 'db_insert', $wpdb->last_error ?: 'Insert failed' );
	}
	detour_rules_bump_cache();
	return (int) $wpdb->insert_id;
}

function detour_update_rule( $id, $data ) {
	global $wpdb;
	$clean = detour_sanitize_rule_input( $data );
	if ( is_wp_error( $clean ) ) {
		return $clean;
	}

	$ok = $wpdb->update(
		detour_rules_table_name(),
		array(
			'match_type'      => $clean['match_type'],
			'source_pattern'  => $clean['source_pattern'],
			'destination_url' => $clean['destination_url'],
			'redirect_status' => $clean['redirect_status'],
			'is_active'       => $clean['is_active'],
			'match_live'      => $clean['match_live'],
			'notes'           => $clean['notes'],
		),
		array( 'id' => (int) $id ),
		array( '%s', '%s', '%s', '%d', '%d', '%d', '%s' ),
		array( '%d' )
	);
	if ( false === $ok ) {
		return new WP_Error( 'db_update', $wpdb->last_error ?: 'Update failed' );
	}
	detour_rules_bump_cache();
	return true;
}

function detour_delete_rule( $id ) {
	global $wpdb;
	$ok = $wpdb->delete(
		detour_rules_table_name(),
		array( 'id' => (int) $id ),
		array( '%d' )
	);
	detour_rules_bump_cache();
	return false !== $ok;
}

function detour_toggle_rule( $id ) {
	global $wpdb;
	$wpdb->query(
		$wpdb->prepare(
			'UPDATE `' . detour_rules_table_name() . '` SET is_active = 1 - is_active WHERE id = %d',
			(int) $id
		)
	);
	detour_rules_bump_cache();
}

function detour_sanitize_rule_input( $data ) {
	$allowed_match  = array( 'exact', 'prefix', 'regex' );
	$allowed_status = array( 301, 302, 307, 308, 410 );

	$match_type = isset( $data['match_type'] ) && in_array( $data['match_type'], $allowed_match, true )
		? $data['match_type'] : 'exact';

	$source_pattern = isset( $data['source_pattern'] ) ? trim( (string) $data['source_pattern'] ) : '';
	if ( '' === $source_pattern ) {
		return new WP_Error( 'invalid_source', 'Source pattern is required.' );
	}
	if ( strlen( $source_pattern ) > 2000 ) {
		return new WP_Error( 'invalid_source', 'Source pattern exceeds 2000 characters.' );
	}

	$redirect_status = isset( $data['redirect_status'] ) ? (int) $data['redirect_status'] : 301;
	if ( ! in_array( $redirect_status, $allowed_status, true ) ) {
		$redirect_status = 301;
	}

	$destination_url = isset( $data['destination_url'] ) ? trim( (string) $data['destination_url'] ) : '';
	if ( 410 !== $redirect_status ) {
		if ( '' === $destination_url ) {
			return new WP_Error( 'invalid_dest', 'Destination URL is required for non-410 rules.' );
		}
		if ( strlen( $destination_url ) > 2000 ) {
			return new WP_Error( 'invalid_dest', 'Destination URL exceeds 2000 characters.' );
		}
		// Allow either an absolute URL or a site-rooted path.
		if ( substr( $destination_url, 0, 1 ) !== '/' && ! filter_var( $destination_url, FILTER_VALIDATE_URL ) ) {
			return new WP_Error( 'invalid_dest', 'Destination must be an absolute URL or a path beginning with /.' );
		}
	} else {
		$destination_url = '';
	}

	if ( 'regex' === $match_type ) {
		$pattern = detour_normalize_regex( $source_pattern );
		if ( @preg_match( $pattern, '' ) === false ) {
			return new WP_Error( 'invalid_regex', 'Invalid regular expression in source pattern.' );
		}
	}

	$is_active = ! empty( $data['is_active'] ) ? 1 : 0;
	// Exact rules always apply to live pages, so the flag only means
	// something for prefix and regex rules.
	$match_live = 'exact' !== $match_type && ! empty( $data['match_live'] ) ? 1 : 0;
	$notes     = isset( $data['notes'] ) ? sanitize_text_field( (string) $data['notes'] ) : '';
	if ( strlen( $notes ) > 255 ) {
		$notes = substr( $notes, 0, 255 );
	}

	return compact( 'match_type', 'source_pattern', 'destination_url', 'redirect_status', 'is_active', 'match_live', 'notes' );
}

// ============================================================
// AJAX endpoints
// ============================================================

add_action( 'wp_ajax_centct_p404_save_rule', 'detour_ajax_save_rule' );
add_action( 'wp_ajax_centct_p404_delete_rule', 'detour_ajax_delete_rule' );
add_action( 'wp_ajax_centct_p404_toggle_rule', 'detour_ajax_toggle_rule' );
add_action( 'wp_ajax_centct_p404_get_rule', 'detour_ajax_get_rule' );

function detour_ajax_check() {
	if ( ! check_ajax_referer( 'detour_rules', 'nonce', false ) ) {
		wp_send_json_error( 'Bad nonce', 403 );
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Insufficient permissions', 403 );
	}
}

function detour_ajax_save_rule() {
	detour_ajax_check();
	$id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
	$data = array(
		'match_type'      => isset( $_POST['match_type'] ) ? wp_unslash( $_POST['match_type'] ) : 'exact',
		'source_pattern'  => isset( $_POST['source_pattern'] ) ? wp_unslash( $_POST['source_pattern'] ) : '',
		'destination_url' => isset( $_POST['destination_url'] ) ? wp_unslash( $_POST['destination_url'] ) : '',
		'redirect_status' => isset( $_POST['redirect_status'] ) ? (int) $_POST['redirect_status'] : 301,
		'is_active'       => isset( $_POST['is_active'] ) ? (int) $_POST['is_active'] : 0,
		'match_live'      => isset( $_POST['match_live'] ) ? (int) $_POST['match_live'] : 0,
		'notes'           => isset( $_POST['notes'] ) ? wp_unslash( $_POST['notes'] ) : '',
	);

	$result = $id > 0 ? detour_update_rule( $id, $data ) : detour_create_rule( $data );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}
	wp_send_json_success( array( 'id' => is_int( $result ) ? $result : $id ) );
}

function detour_ajax_delete_rule() {
	detour_ajax_check();
	$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
	if ( $id <= 0 ) {
		wp_send_json_error( 'Bad id' );
	}
	if ( detour_delete_rule( $id ) ) {
		wp_send_json_success();
	}
	wp_send_json_error( 'Delete failed' );
}

function detour_ajax_toggle_rule() {
	detour_ajax_check();
	$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
	if ( $id <= 0 ) {
		wp_send_json_error( 'Bad id' );
	}
	detour_toggle_rule( $id );
	wp_send_json_success();
}

function detour_ajax_get_rule() {
	detour_ajax_check();
	$id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
	$rule = detour_get_rule( $id );
	if ( ! $rule ) {
		wp_send_json_error( 'Not found', 404 );
	}
	wp_send_json_success( $rule );
}

// ============================================================
// Admin UI for the Rules tab
// ============================================================

function detour_render_rules_tab() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$current_page = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
	$per_page     = 20;
	$orderby      = isset( $_GET['orderby'] ) ? sanitize_text_field( $_GET['orderby'] ) : 'id';
	$order        = isset( $_GET['order'] ) ? sanitize_text_field( $_GET['order'] ) : 'DESC';
	$search       = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';

	$data = detour_get_rules(
		array(
			'page'     => $current_page,
			'per_page' => $per_page,
			'orderby'  => $orderby,
			'order'    => $order,
			'search'   => $search,
		)
	);

	$promote_url = isset( $_GET['promote'] ) ? esc_url_raw( wp_unslash( $_GET['promote'] ) ) : '';
	$nonce       = wp_create_nonce( 'detour_rules' );
	?>
	<div class="form-section centct-rules-section">
		<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
			<div>
				<h3 style="margin:0;"><span class="dashicons dashicons-randomize"></span> Per-URL Rules</h3>
				<p style="margin:6px 0 0;color:#6b7280;">Match incoming requests against rules and redirect each one to its own destination, or return <code>410 Gone</code> for permanently-deleted pages.</p>
			</div>
			<button type="button" class="button button-primary" id="centct-rule-new">+ Add Rule</button>
		</div>

		<form method="get" style="margin-bottom:16px;">
			<input type="hidden" name="page" value="detour.php">
			<input type="hidden" name="mytab" value="rules">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search source, destination, or notes" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;width:320px;">
			<button type="submit" class="button">Search</button>
			<?php if ( '' !== $search ) : ?>
				<a href="<?php echo esc_url( remove_query_arg( 's' ) ); ?>" class="button">Clear</a>
			<?php endif; ?>
		</form>

		<div class="data-table">
			<table>
				<thead>
					<tr>
						<th width="60">On</th>
						<th width="80">Type</th>
						<th>Source</th>
						<th>Destination</th>
						<th width="80">Code</th>
						<th width="80">Hits</th>
						<th width="160">Last Hit</th>
						<th width="160">Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $data['rows'] ) ) : ?>
						<tr><td colspan="8" style="text-align:center;padding:40px 20px;color:#6b7280;">
							<p style="margin:0;">No rules yet. Click <strong>+ Add Rule</strong> above, or <a href="?page=detour.php&amp;mytab=404urls">promote a logged 404</a>.</p>
						</td></tr>
						<?php
					else :
						foreach ( $data['rows'] as $r ) :
							$is_410     = (int) 410 === $r['redirect_status'];
							$type_color = array(
								'exact'  => '#16a34a',
								'prefix' => '#0ea5e9',
								'regex'  => '#a855f7',
							);
							?>
						<tr data-rule-id="<?php echo (int) $r['id']; ?>">
							<td><input type="checkbox" class="centct-toggle" <?php checked( (int) $r['is_active'], 1 ); ?>></td>
							<td>
								<span style="background:<?php echo esc_attr( $type_color[ $r['match_type'] ] ); ?>;color:#fff;padding:3px 8px;border-radius:4px;font-size:11px;text-transform:uppercase;letter-spacing:0.4px;">
									<?php echo esc_html( $r['match_type'] ); ?>
								</span>
								<?php if ( ! empty( $r['match_live'] ) ) : ?>
									<span title="Also redirects pages that still resolve" style="display:inline-block;margin-top:4px;background:#f59e0b;color:#fff;padding:2px 6px;border-radius:4px;font-size:10px;text-transform:uppercase;">live</span>
								<?php endif; ?>
							</td>
							<td style="font-family:monospace;font-size:12px;word-break:break-all;"><?php echo esc_html( $r['source_pattern'] ); ?></td>
							<td style="font-family:monospace;font-size:12px;word-break:break-all;">
								<?php echo $is_410 ? '<em style="color:#dc2626;">Gone</em>' : esc_html( $r['destination_url'] ); ?>
							</td>
							<td><strong><?php echo (int) $r['redirect_status']; ?></strong></td>
							<td><?php echo number_format( (int) $r['hit_count'] ); ?></td>
							<td style="font-size:12px;color:#6b7280;"><?php echo $r['last_hit_at'] ? esc_html( $r['last_hit_at'] ) : '-'; ?></td>
							<td>
								<button type="button" class="button button-small centct-edit">Edit</button>
								<button type="button" class="button button-small centct-delete" style="color:#dc2626;">Delete</button>
							</td>
						</tr>
											<?php
					endforeach;
endif;
					?>
				</tbody>
			</table>
		</div>

		<?php if ( $data['pages'] > 1 ) : ?>
			<div style="margin-top:16px;display:flex;justify-content:space-between;align-items:center;">
				<span><?php echo number_format( $data['total'] ); ?> rules</span>
				<div>
					<?php
					$base = remove_query_arg( 'paged' );
					for ( $i = 1; $i <= $data['pages']; $i++ ) {
						$cls = $i === $current_page ? 'button button-primary' : 'button';
						$url = add_query_arg( 'paged', $i, $base );
						echo "<a class='" . esc_attr( $cls ) . "' href='" . esc_url( $url ) . "'>{$i}</a> ";
					}
					?>
				</div>
			</div>
		<?php endif; ?>
	</div>

	<div id="centct-rule-modal" class="p404-modal" style="display:none;">
		<div class="p404-modal-content" style="max-width:560px;">
			<div class="p404-modal-header">
				<span class="dashicons dashicons-randomize"></span>
				<h2 id="centct-rule-modal-title">Add Rule</h2>
			</div>
			<div class="p404-modal-body">
				<input type="hidden" id="centct-rule-id" value="0">
				<p style="margin-top:0;">
					<label><strong>Match type</strong></label><br>
					<select id="centct-rule-match-type" style="width:100%;">
						<option value="exact">Exact match (single URL)</option>
						<option value="prefix">Prefix match (URL starts with)</option>
						<option value="regex">Regular expression</option>
					</select>
				</p>
				<p>
					<label><strong>Source pattern</strong></label><br>
					<input type="text" id="centct-rule-source" placeholder="/old-page/" style="width:100%;font-family:monospace;">
					<span style="color:#6b7280;font-size:12px;">A path like <code>/old-page/</code> or a full URL. Prefix matches the start of the path. Regex example: <code>~^/old/(\d+)/?$~</code> (use <code>$1</code> in the destination).</span>
				</p>
				<p>
					<label><strong>Status code</strong></label><br>
					<select id="centct-rule-status" style="width:100%;">
						<option value="301">301 Moved Permanently</option>
						<option value="302">302 Found (temporary)</option>
						<option value="307">307 Temporary (preserves method)</option>
						<option value="308">308 Permanent (preserves method)</option>
						<option value="410">410 Gone (no destination)</option>
					</select>
				</p>
				<p id="centct-rule-dest-row">
					<label><strong>Destination URL</strong></label><br>
					<input type="text" id="centct-rule-dest" placeholder="/new-page/ or https://..." style="width:100%;font-family:monospace;">
					<span style="color:#6b7280;font-size:12px;">Use <code>{path}</code> in a prefix-mode destination to append the unmatched tail.</span>
				</p>
				<p>
					<label><strong>Notes (optional)</strong></label><br>
					<input type="text" id="centct-rule-notes" maxlength="255" placeholder="Why this rule exists..." style="width:100%;">
				</p>
				<p id="centct-rule-live-row">
					<label><input type="checkbox" id="centct-rule-live"> <strong>Also redirect live pages</strong></label><br>
					<span style="color:#6b7280;font-size:12px;">By default prefix and regex rules only catch 404s. Tick this to redirect matching URLs even when the page still exists (for example, a whole-site move). Exact rules always do.</span>
				</p>
				<p>
					<label><input type="checkbox" id="centct-rule-active" checked> <strong>Active</strong></label>
				</p>
				<p id="centct-rule-error" style="color:#dc2626;display:none;"></p>
			</div>
			<div class="p404-modal-footer">
				<button type="button" class="button" id="centct-rule-cancel">Cancel</button>
				<button type="button" class="button button-primary" id="centct-rule-save">Save Rule</button>
			</div>
		</div>
	</div>

	<script>
	(function($){
		var nonce   = '<?php echo esc_js( $nonce ); ?>';
		var ajaxurl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
		var promote = <?php echo wp_json_encode( $promote_url ); ?>;

		function setVal(rule) {
			$('#centct-rule-modal-title').text(rule.id ? 'Edit Rule' : 'Add Rule');
			$('#centct-rule-id').val(rule.id || 0);
			$('#centct-rule-match-type').val(rule.match_type || 'exact');
			$('#centct-rule-source').val(rule.source_pattern || '');
			$('#centct-rule-dest').val(rule.destination_url || '');
			$('#centct-rule-status').val(rule.redirect_status || 301);
			$('#centct-rule-notes').val(rule.notes || '');
			$('#centct-rule-active').prop('checked', rule.id ? !!parseInt(rule.is_active, 10) : true);
			$('#centct-rule-live').prop('checked', !!parseInt(rule.match_live || 0, 10));
			$('#centct-rule-error').hide().text('');
			toggleDestRow();
			toggleLiveRow();
		}
		function openModal(rule) { setVal(rule || {}); $('#centct-rule-modal').css('display', 'flex'); }
		function closeModal()    { $('#centct-rule-modal').hide(); }
		function toggleDestRow() {
			$('#centct-rule-dest-row').toggle(parseInt($('#centct-rule-status').val(), 10) !== 410);
		}

		function toggleLiveRow() {
			$('#centct-rule-live-row').toggle($('#centct-rule-match-type').val() !== 'exact');
		}

		$('#centct-rule-status').on('change', toggleDestRow);
		$('#centct-rule-match-type').on('change', toggleLiveRow);
		$('#centct-rule-new').on('click', function(){ openModal(); });
		$('#centct-rule-cancel').on('click', closeModal);

		$('#centct-rule-save').on('click', function(){
			var data = {
				action: 'centct_p404_save_rule',
				nonce: nonce,
				id: parseInt($('#centct-rule-id').val(), 10) || 0,
				match_type: $('#centct-rule-match-type').val(),
				source_pattern: $('#centct-rule-source').val(),
				destination_url: $('#centct-rule-dest').val(),
				redirect_status: parseInt($('#centct-rule-status').val(), 10),
				notes: $('#centct-rule-notes').val(),
				is_active: $('#centct-rule-active').is(':checked') ? 1 : 0,
				match_live: $('#centct-rule-live').is(':checked') ? 1 : 0
			};
			$('#centct-rule-save').prop('disabled', true);
			$.post(ajaxurl, data).done(function(resp){
				if (resp && resp.success) { location.reload(); }
				else { $('#centct-rule-error').text(resp && resp.data ? resp.data : 'Save failed').show();
					$('#centct-rule-save').prop('disabled', false); }
			}).fail(function(){
				$('#centct-rule-error').text('Network error.').show();
				$('#centct-rule-save').prop('disabled', false);
			});
		});

		$(document).on('click', '.centct-edit', function(){
			var id = $(this).closest('tr').data('rule-id');
			$.post(ajaxurl, {action:'centct_p404_get_rule', nonce:nonce, id:id}).done(function(resp){
				if (resp && resp.success) openModal(resp.data);
			});
		});

		$(document).on('click', '.centct-delete', function(){
			if (!confirm('Delete this rule?')) return;
			var id = $(this).closest('tr').data('rule-id');
			$.post(ajaxurl, {action:'centct_p404_delete_rule', nonce:nonce, id:id}).done(function(){ location.reload(); });
		});

		$(document).on('change', '.centct-toggle', function(){
			var id = $(this).closest('tr').data('rule-id');
			$.post(ajaxurl, {action:'centct_p404_toggle_rule', nonce:nonce, id:id});
		});

		if (promote) { openModal({source_pattern: promote, match_type: 'exact', redirect_status: 301}); }
	})(jQuery);
	</script>
	<?php
}
