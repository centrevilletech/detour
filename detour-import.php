<?php
/**
 * Detour - bulk import shared by WP-CLI and the REST API, plus readers that
 * turn rules stored by the Redirection and Yoast SEO Premium plugins into
 * Detour import rows.
 *
 * An import row uses the CSV column names: source, destination, status,
 * match_type, notes, active, live.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turn one import row into the input array detour_create_rule() expects.
 */
function detour_import_row_to_input( $row ) {
	$row   = array_change_key_case( (array) $row, CASE_LOWER );
	$input = array(
		'match_type'      => isset( $row['match_type'] ) && '' !== $row['match_type'] ? strtolower( trim( $row['match_type'] ) ) : 'exact',
		'source_pattern'  => isset( $row['source'] ) ? (string) $row['source'] : '',
		'destination_url' => isset( $row['destination'] ) ? (string) $row['destination'] : '',
		'redirect_status' => isset( $row['status'] ) && '' !== $row['status'] ? (int) $row['status'] : 301,
		'notes'           => isset( $row['notes'] ) ? (string) $row['notes'] : '',
		'is_active'       => isset( $row['active'] ) && '' !== $row['active'] ? (int) (bool) $row['active'] : 1,
		'match_live'      => isset( $row['live'] ) && '' !== $row['live'] ? (int) (bool) $row['live'] : 0,
	);

	/**
	 * Filter the rule input built from an import row, so later columns can
	 * be mapped without changing the importer.
	 *
	 * @param array $input Input for detour_create_rule().
	 * @param array $row   The raw import row.
	 */
	return apply_filters( 'detour_import_rule_input', $input, $row );
}

/**
 * Create or update rules from import rows. A row whose source and match type
 * already exist updates that rule instead of adding a duplicate.
 *
 * @param array $rows Import rows.
 * @param array $args dry_run (bool): validate and count without writing.
 *                    replace (bool): delete every existing rule first. The
 *                    delete and the import share one transaction, so a
 *                    database failure leaves the old rules in place.
 * @return array created, updated, unchanged, failed counts and an errors list
 *               of array( 'row' => 1-based index, 'source' => ..., 'message' => ... ).
 */
function detour_import_rules( $rows, $args = array() ) {
	global $wpdb;
	$args    = wp_parse_args(
		$args,
		array(
			'dry_run' => false,
			'replace' => false,
		)
	);
	$dry_run = (bool) $args['dry_run'];
	$replace = (bool) $args['replace'];
	$table   = detour_rules_table_name();

	$result = array(
		'created'   => 0,
		'updated'   => 0,
		'unchanged' => 0,
		'failed'    => 0,
		'errors'    => array(),
	);

	if ( ! $dry_run ) {
		$wpdb->query( 'START TRANSACTION' );
		if ( $replace ) {
			$wpdb->query( "DELETE FROM `{$table}`" );
		}
	}

	$seen = array();
	foreach ( array_values( (array) $rows ) as $i => $row ) {
		$input = detour_import_row_to_input( $row );
		$clean = detour_sanitize_rule_input( $input );
		if ( is_wp_error( $clean ) ) {
			++$result['failed'];
			$result['errors'][] = array(
				'row'     => $i + 1,
				'source'  => $input['source_pattern'],
				'message' => $clean->get_error_message(),
			);
			continue;
		}

		$key      = $clean['match_type'] . "\0" . $clean['source_pattern'];
		$existing = null;
		if ( ! $replace || isset( $seen[ $key ] ) ) {
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` WHERE source_hash = UNHEX(MD5(%s)) AND source_pattern = %s AND match_type = %s LIMIT 1",
					$clean['source_pattern'],
					$clean['source_pattern'],
					$clean['match_type']
				),
				ARRAY_A
			);
		}
		if ( $dry_run && $replace && isset( $seen[ $key ] ) ) {
			$existing = array( 'id' => 0 ); // An earlier row in this batch would have created it.
		}
		$seen[ $key ] = true;

		if ( $existing && isset( $existing['destination_url'] )
			&& (string) $existing['destination_url'] === $clean['destination_url']
			&& (int) $existing['redirect_status'] === $clean['redirect_status']
			&& (int) $existing['is_active'] === $clean['is_active']
			&& (int) $existing['match_live'] === $clean['match_live']
			&& (string) $existing['notes'] === $clean['notes'] ) {
			++$result['unchanged'];
			continue;
		}

		if ( $dry_run ) {
			++$result[ $existing ? 'updated' : 'created' ];
			continue;
		}

		$saved = $existing ? detour_update_rule( (int) $existing['id'], $input ) : detour_create_rule( $input );
		if ( is_wp_error( $saved ) ) {
			++$result['failed'];
			$result['errors'][] = array(
				'row'     => $i + 1,
				'source'  => $input['source_pattern'],
				'message' => $saved->get_error_message(),
			);
			continue;
		}
		++$result[ $existing ? 'updated' : 'created' ];
	}

	if ( ! $dry_run ) {
		// A replace that could not write a single row would leave the site with
		// no rules at all, so it rolls back instead.
		$wrote = $result['created'] + $result['updated'] + $result['unchanged'];
		if ( $replace && 0 === $wrote && $result['failed'] > 0 ) {
			$wpdb->query( 'ROLLBACK' );
			$result['rolled_back'] = true;
		} else {
			$wpdb->query( 'COMMIT' );
		}
		detour_rules_bump_cache();
	}

	return $result;
}

/**
 * Build the regex Detour stores for a pattern written for another plugin.
 * Always adds `~` delimiters, because a pattern such as `/old/(.*)` would
 * otherwise be read as delimited by its leading slash.
 */
function detour_wrap_foreign_regex( $pattern, $case_insensitive ) {
	return '~' . str_replace( '~', '\~', (string) $pattern ) . '~' . ( $case_insensitive ? 'i' : '' );
}

/**
 * Exact-match source for a rule that ignored letter case in its old plugin.
 * Detour's exact lookup tries the request path as sent and then in lowercase,
 * so storing the path in lowercase keeps the rule case-insensitive. The query
 * string keeps its case.
 */
function detour_case_insensitive_source( $source ) {
	$q = strpos( $source, '?' );
	return false === $q ? strtolower( $source ) : strtolower( substr( $source, 0, $q ) ) . substr( $source, $q );
}

/**
 * Lookup key for spotting two exact rules that would answer the same request.
 */
function detour_exact_dedupe_key( $source ) {
	$parts = wp_parse_url( $source );
	$path  = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
	$path  = '/' === $path ? '/' : untrailingslashit( strtolower( $path ) );
	return $path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
}

/**
 * Read the rules of the Redirection plugin (by John Godley) from its tables.
 *
 * Only rules in WordPress-module groups that match on the URL alone and
 * redirect to a URL or return an error are convertible. Redirection applies
 * the first matching rule in position order, so when two exact rules would
 * answer the same request the later one is skipped, as it never fired.
 * Redirection checks every request, not only 404s, so regex rules are
 * imported with `live` set.
 *
 * @return array|WP_Error array( 'rows' => import rows, 'skipped' => list of
 *                        array( 'id', 'source', 'reason' ) ).
 */
function detour_read_redirection_rules() {
	global $wpdb;
	$items  = $wpdb->prefix . 'redirection_items';
	$groups = $wpdb->prefix . 'redirection_groups';

	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $items ) ) !== $items ) {
		return new WP_Error( 'detour_no_redirection', 'The Redirection plugin tables were not found.', array( 'status' => 404 ) );
	}

	$rows = $wpdb->get_results(
		"SELECT i.*, g.status AS group_status, g.module_id, g.name AS group_name
		   FROM `{$items}` i
		   LEFT JOIN `{$groups}` g ON g.id = i.group_id
		  ORDER BY i.position ASC, i.id ASC",
		ARRAY_A
	);

	$out     = array();
	$skipped = array();
	$taken   = array();
	foreach ( $rows as $r ) {
		$skip = function ( $reason ) use ( &$skipped, $r ) {
			$skipped[] = array(
				'id'     => (int) $r['id'],
				'source' => $r['url'],
				'reason' => $reason,
			);
		};

		if ( 1 !== (int) $r['module_id'] ) {
			$skip( 'Group is not in the WordPress module.' );
			continue;
		}
		if ( 'url' !== $r['match_type'] ) {
			$skip( "Match type \"{$r['match_type']}\" depends on more than the URL." );
			continue;
		}

		$code = (int) $r['action_code'];
		if ( 'url' === $r['action_type'] && in_array( $code, array( 301, 302, 307, 308 ), true ) ) {
			$destination = (string) $r['action_data'];
		} elseif ( 'error' === $r['action_type'] && 410 === $code ) {
			$destination = '';
		} else {
			$skip( "Action \"{$r['action_type']}\" with code {$code} has no Detour equivalent." );
			continue;
		}

		$flags = array();
		$data  = json_decode( (string) $r['match_data'], true );
		if ( isset( $data['source'] ) && is_array( $data['source'] ) ) {
			$flags = $data['source'];
		}
		$case_insensitive = empty( $flags['flag_case'] );

		if ( (int) $r['regex'] ) {
			$source     = detour_wrap_foreign_regex( $r['url'], $case_insensitive );
			$match_type = 'regex';
		} else {
			$source     = $case_insensitive ? detour_case_insensitive_source( $r['url'] ) : $r['url'];
			$match_type = 'exact';
			$key        = detour_exact_dedupe_key( $source );
			if ( isset( $taken[ $key ] ) ) {
				$skip( 'An earlier Redirection rule (#' . $taken[ $key ] . ') already handles this URL.' );
				continue;
			}
			$taken[ $key ] = (int) $r['id'];
		}

		$active = 'enabled' === $r['status'] && 'disabled' !== $r['group_status'];
		$out[]  = array(
			'source'      => $source,
			'destination' => $destination,
			'status'      => $code,
			'match_type'  => $match_type,
			'notes'       => substr( 'Redirection #' . $r['id'] . ( '' !== (string) $r['title'] ? ': ' . $r['title'] : '' ), 0, 255 ),
			'active'      => $active ? 1 : 0,
			'live'        => 'regex' === $match_type ? 1 : 0,
		);
	}

	return array(
		'rows'    => $out,
		'skipped' => $skipped,
	);
}

/**
 * Read the redirects stored by Yoast SEO Premium.
 *
 * Yoast keeps every redirect in the `wpseo-premium-redirects-base` option,
 * with origins written without the leading slash. Plain origins become exact
 * rules. Regex origins are wrapped for Detour, and a leading `^` that is not
 * followed by `/` becomes `^/?`, so the pattern matches whether or not the
 * path it is tested against starts with a slash. Yoast checks every request,
 * so regex rules are imported with `live` set.
 *
 * @return array array( 'rows' => import rows, 'skipped' => list of
 *               array( 'id', 'source', 'reason' ) ).
 */
function detour_read_yoast_rules() {
	$stored = get_option( 'wpseo-premium-redirects-base', array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}

	$out     = array();
	$skipped = array();
	$taken   = array();
	foreach ( array_values( $stored ) as $i => $r ) {
		$origin = isset( $r['origin'] ) ? (string) $r['origin'] : '';
		$target = isset( $r['url'] ) ? (string) $r['url'] : '';
		$code   = isset( $r['type'] ) ? (int) $r['type'] : 301;
		$format = isset( $r['format'] ) ? (string) $r['format'] : 'plain';

		if ( '' === $origin ) {
			continue;
		}
		if ( ! in_array( $code, array( 301, 302, 307, 308, 410 ), true ) ) {
			$skipped[] = array(
				'id'     => $i,
				'source' => $origin,
				'reason' => "Status {$code} has no Detour equivalent.",
			);
			continue;
		}

		if ( 410 !== $code && '' !== $target && ! preg_match( '#^(https?:)?//#i', $target ) && '/' !== $target[0] ) {
			$target = '/' . $target;
		}

		if ( 'regex' === $format ) {
			$pattern    = preg_replace( '/^\^(?!\/)/', '^/?', $origin );
			$source     = detour_wrap_foreign_regex( $pattern, true );
			$match_type = 'regex';
		} else {
			$source     = preg_match( '#^https?://#i', $origin ) ? $origin : '/' . ltrim( $origin, '/' );
			$source     = detour_case_insensitive_source( $source );
			$match_type = 'exact';
			$key        = detour_exact_dedupe_key( $source );
			if ( isset( $taken[ $key ] ) ) {
				$skipped[] = array(
					'id'     => $i,
					'source' => $origin,
					'reason' => 'An earlier Yoast redirect already handles this URL.',
				);
				continue;
			}
			$taken[ $key ] = $i;
		}

		$out[] = array(
			'source'      => $source,
			'destination' => 410 === $code ? '' : $target,
			'status'      => $code,
			'match_type'  => $match_type,
			'notes'       => 'Yoast SEO Premium redirect',
			'active'      => 1,
			'live'        => 'regex' === $match_type ? 1 : 0,
		);
	}

	return array(
		'rows'    => $out,
		'skipped' => $skipped,
	);
}
