<?php
/**
 * Detour - REST API under /wp-json/detour/v1.
 *
 * Every route requires manage_options, so it works with cookie auth plus a
 * nonce in wp-admin and with application passwords from scripts.
 *
 *   GET    rules                  List rules (page, per_page, search, orderby, order).
 *   POST   rules                  Create a rule from one import row.
 *   GET    rules/<id>             Read a rule.
 *   PUT    rules/<id>             Replace a rule with an import row.
 *   DELETE rules/<id>             Delete a rule.
 *   POST   rules/import           Bulk upsert: { rules: [rows], dry_run, replace }.
 *   GET    rules/export           Every rule as import rows (plus id and hits).
 *   GET    test?url=...&live=1    Which rule would handle a URL. Counts no hit.
 *   GET    import/redirection     Rules from the Redirection plugin, as import rows.
 *   POST   import/redirection     Import them: { dry_run, replace }.
 *   GET    import/yoast           Redirects from Yoast SEO Premium, as import rows.
 *   POST   import/yoast           Import them: { dry_run, replace }.
 *
 * Import rows use the CSV column names: source, destination, status,
 * match_type, notes, active, live.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'detour_register_rest_routes' );

function detour_rest_permission() {
	return current_user_can( 'manage_options' );
}

function detour_register_rest_routes() {
	$ns   = 'detour/v1';
	$perm = 'detour_rest_permission';
	$bool = array(
		'type'    => 'boolean',
		'default' => false,
	);

	register_rest_route(
		$ns,
		'/rules',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'detour_rest_list_rules',
				'permission_callback' => $perm,
				'args'                => array(
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 100,
						'minimum' => 1,
						'maximum' => 1000,
					),
					'search'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'orderby'  => array(
						'type'    => 'string',
						'default' => 'id',
					),
					'order'    => array(
						'type'    => 'string',
						'default' => 'ASC',
					),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'detour_rest_create_rule',
				'permission_callback' => $perm,
			),
		)
	);

	register_rest_route(
		$ns,
		'/rules/(?P<id>\d+)',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'detour_rest_get_rule',
				'permission_callback' => $perm,
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => 'detour_rest_update_rule',
				'permission_callback' => $perm,
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => 'detour_rest_delete_rule',
				'permission_callback' => $perm,
			),
		)
	);

	register_rest_route(
		$ns,
		'/rules/import',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'detour_rest_import_rules',
			'permission_callback' => $perm,
			'args'                => array(
				'rules'   => array(
					'type'     => 'array',
					'required' => true,
				),
				'dry_run' => $bool,
				'replace' => $bool,
			),
		)
	);

	register_rest_route(
		$ns,
		'/rules/export',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'detour_rest_export_rules',
			'permission_callback' => $perm,
		)
	);

	register_rest_route(
		$ns,
		'/test',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'detour_rest_test_url',
			'permission_callback' => $perm,
			'args'                => array(
				'url'  => array(
					'type'     => 'string',
					'required' => true,
				),
				'live' => $bool,
			),
		)
	);

	foreach ( array(
		'redirection' => 'detour_read_redirection_rules',
		'yoast'       => 'detour_read_yoast_rules',
	) as $source => $reader ) {
		register_rest_route(
			$ns,
			'/import/' . $source,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => function () use ( $reader ) {
						$read = call_user_func( $reader );
						if ( is_wp_error( $read ) ) {
							return $read;
						}
						$read['total'] = count( $read['rows'] );
						return rest_ensure_response( $read );
					},
					'permission_callback' => $perm,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => function ( WP_REST_Request $request ) use ( $reader ) {
						$read = call_user_func( $reader );
						if ( is_wp_error( $read ) ) {
							return $read;
						}
						$result            = detour_import_rules(
							$read['rows'],
							array(
								'dry_run' => (bool) $request['dry_run'],
								'replace' => (bool) $request['replace'],
							)
						);
						$result['skipped'] = $read['skipped'];
						return rest_ensure_response( $result );
					},
					'permission_callback' => $perm,
					'args'                => array(
						'dry_run' => $bool,
						'replace' => $bool,
					),
				),
			)
		);
	}
}

/**
 * Shape a database row for API output, using the import column names.
 */
function detour_rest_prepare_rule( $row ) {
	return array(
		'id'          => (int) $row['id'],
		'source'      => $row['source_pattern'],
		'destination' => (string) $row['destination_url'],
		'status'      => (int) $row['redirect_status'],
		'match_type'  => $row['match_type'],
		'notes'       => (string) $row['notes'],
		'active'      => (int) $row['is_active'],
		'live'        => isset( $row['match_live'] ) ? (int) $row['match_live'] : 0,
		'hit_count'   => (int) $row['hit_count'],
		'last_hit_at' => $row['last_hit_at'],
		'created_at'  => $row['created_at'],
		'updated_at'  => $row['updated_at'],
	);
}

function detour_rest_rule_or_404( $id ) {
	$row = detour_get_rule( (int) $id );
	if ( ! $row ) {
		return new WP_Error( 'detour_not_found', 'Rule not found.', array( 'status' => 404 ) );
	}
	return $row;
}

function detour_rest_list_rules( WP_REST_Request $request ) {
	$data     = detour_get_rules(
		array(
			'page'     => (int) $request['page'],
			'per_page' => (int) $request['per_page'],
			'search'   => (string) $request['search'],
			'orderby'  => (string) $request['orderby'],
			'order'    => (string) $request['order'],
		)
	);
	$response = rest_ensure_response( array_map( 'detour_rest_prepare_rule', $data['rows'] ) );
	$response->header( 'X-WP-Total', (string) $data['total'] );
	$response->header( 'X-WP-TotalPages', (string) $data['pages'] );
	return $response;
}

function detour_rest_get_rule( WP_REST_Request $request ) {
	$row = detour_rest_rule_or_404( $request['id'] );
	return is_wp_error( $row ) ? $row : rest_ensure_response( detour_rest_prepare_rule( $row ) );
}

function detour_rest_create_rule( WP_REST_Request $request ) {
	$id = detour_create_rule( detour_import_row_to_input( $request->get_json_params() ) );
	if ( is_wp_error( $id ) ) {
		$id->add_data( array( 'status' => 400 ) );
		return $id;
	}
	$response = rest_ensure_response( detour_rest_prepare_rule( detour_get_rule( $id ) ) );
	$response->set_status( 201 );
	return $response;
}

function detour_rest_update_rule( WP_REST_Request $request ) {
	$row = detour_rest_rule_or_404( $request['id'] );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	// Fields left out of the body keep their current values.
	$merged = array_merge(
		array_diff_key( detour_rest_prepare_rule( $row ), array_flip( array( 'id', 'hit_count', 'last_hit_at', 'created_at', 'updated_at' ) ) ),
		(array) $request->get_json_params()
	);
	$ok     = detour_update_rule( (int) $row['id'], detour_import_row_to_input( $merged ) );
	if ( is_wp_error( $ok ) ) {
		$ok->add_data( array( 'status' => 400 ) );
		return $ok;
	}
	return rest_ensure_response( detour_rest_prepare_rule( detour_get_rule( (int) $row['id'] ) ) );
}

function detour_rest_delete_rule( WP_REST_Request $request ) {
	$row = detour_rest_rule_or_404( $request['id'] );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	detour_delete_rule( (int) $row['id'] );
	return rest_ensure_response(
		array(
			'deleted'  => true,
			'previous' => detour_rest_prepare_rule( $row ),
		)
	);
}

function detour_rest_import_rules( WP_REST_Request $request ) {
	return rest_ensure_response(
		detour_import_rules(
			(array) $request['rules'],
			array(
				'dry_run' => (bool) $request['dry_run'],
				'replace' => (bool) $request['replace'],
			)
		)
	);
}

function detour_rest_export_rules() {
	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT * FROM `' . detour_rules_table_name() . '` ORDER BY id ASC', ARRAY_A );
	return rest_ensure_response( array_map( 'detour_rest_prepare_rule', $rows ) );
}

function detour_rest_test_url( WP_REST_Request $request ) {
	$url = (string) $request['url'];
	if ( '/' === substr( $url, 0, 1 ) ) {
		$url = untrailingslashit( home_url() ) . $url;
	}
	if ( detour_is_excluded_url( $url ) ) {
		return rest_ensure_response(
			array(
				'url'      => $url,
				'excluded' => true,
				'rule'     => null,
			)
		);
	}
	$rule = detour_match_rule( $url, (bool) $request['live'] );
	return rest_ensure_response(
		array(
			'url'      => $url,
			'excluded' => false,
			'rule'     => $rule ? detour_rest_prepare_rule( $rule ) : null,
			'query'    => $rule && ! empty( $rule['_query'] ) ? $rule['_query'] : '',
		)
	);
}
