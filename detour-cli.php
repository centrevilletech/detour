<?php
/**
 * Detour - WP-CLI commands for bulk rule management.
 *
 *   wp detour import rules.csv [--dry-run] [--replace]
 *   wp detour export [--format=csv]
 *   wp detour test https://example.com/old-page/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class Detour_CLI_Command {

	/**
	 * Import rules from a CSV file.
	 *
	 * The first row is a header. Recognised columns: source, destination,
	 * status, match_type, notes, active. Only `source` is required;
	 * `destination` is required unless status is 410. A row whose source and
	 * match type already exist updates that rule instead of adding a duplicate.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the CSV file.
	 *
	 * [--dry-run]
	 * : Validate every row and report what would change, without writing.
	 *
	 * [--replace]
	 * : Delete all existing rules before importing.
	 *
	 * @when after_wp_load
	 */
	public function import( $args, $assoc ) {
		$file    = $args[0];
		$dry_run = ! empty( $assoc['dry-run'] );
		$replace = ! empty( $assoc['replace'] );

		if ( ! is_readable( $file ) ) {
			WP_CLI::error( "Cannot read {$file}." );
		}
		$fh     = fopen( $file, 'r' );
		$header = fgetcsv( $fh );
		if ( ! $header ) {
			WP_CLI::error( 'The CSV is empty.' );
		}
		$header = array_map(
			function ( $h ) {
				return strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', $h ) ) );
			},
			$header
		);
		if ( ! in_array( 'source', $header, true ) ) {
			WP_CLI::error( 'The CSV needs a "source" column.' );
		}

		global $wpdb;
		$table = detour_rules_table_name();
		if ( $replace && ! $dry_run ) {
			$wpdb->query( "DELETE FROM `{$table}`" );
			detour_rules_bump_cache();
		}

		$counts = array(
			'created' => 0,
			'updated' => 0,
			'failed'  => 0,
		);
		$line   = 1;
		while ( ( $row = fgetcsv( $fh ) ) !== false ) {
			++$line;
			if ( array( null ) === $row || '' === trim( implode( '', $row ) ) ) {
				continue;
			}
			$row  = array_pad( $row, count( $header ), '' );
			$data = array_combine( $header, array_slice( $row, 0, count( $header ) ) );

			$input = array(
				'match_type'      => isset( $data['match_type'] ) && '' !== $data['match_type'] ? $data['match_type'] : 'exact',
				'source_pattern'  => $data['source'],
				'destination_url' => isset( $data['destination'] ) ? $data['destination'] : '',
				'redirect_status' => isset( $data['status'] ) && '' !== $data['status'] ? (int) $data['status'] : 301,
				'notes'           => isset( $data['notes'] ) ? $data['notes'] : '',
				'is_active'       => isset( $data['active'] ) && '' !== $data['active'] ? (int) $data['active'] : 1,
			);

			$clean = detour_sanitize_rule_input( $input );
			if ( is_wp_error( $clean ) ) {
				++$counts['failed'];
				WP_CLI::warning( "Line {$line}: " . $clean->get_error_message() );
				continue;
			}

			$existing = $replace ? null : $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM `{$table}` WHERE source_hash = UNHEX(MD5(%s)) AND source_pattern = %s AND match_type = %s LIMIT 1",
					$clean['source_pattern'],
					$clean['source_pattern'],
					$clean['match_type']
				)
			);

			if ( $dry_run ) {
				++$counts[ $existing ? 'updated' : 'created' ];
				continue;
			}

			$result = $existing ? detour_update_rule( (int) $existing, $input ) : detour_create_rule( $input );
			if ( is_wp_error( $result ) ) {
				++$counts['failed'];
				WP_CLI::warning( "Line {$line}: " . $result->get_error_message() );
				continue;
			}
			++$counts[ $existing ? 'updated' : 'created' ];
		}
		fclose( $fh );

		$verb = $dry_run ? 'Would create' : 'Created';
		WP_CLI::success( "{$verb} {$counts['created']}, updated {$counts['updated']}, failed {$counts['failed']}." );
	}

	/**
	 * Export all rules.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : csv, json, or table.
	 * ---
	 * default: csv
	 * ---
	 *
	 * @when after_wp_load
	 */
	public function export( $args, $assoc ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT id, match_type, source_pattern AS source, destination_url AS destination, redirect_status AS status,
			        is_active AS active, hit_count, last_hit_at, notes
			   FROM `' . detour_rules_table_name() . '` ORDER BY id',
			ARRAY_A
		);
		$fields = array( 'id', 'match_type', 'source', 'destination', 'status', 'active', 'hit_count', 'last_hit_at', 'notes' );
		WP_CLI\Utils\format_items( $assoc['format'], $rows, $fields );
	}

	/**
	 * Show which rule, if any, would handle a URL. Does not count a hit.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : A full URL or a site-rooted path.
	 *
	 * @when after_wp_load
	 */
	public function test( $args ) {
		$url = $args[0];
		if ( '/' === substr( $url, 0, 1 ) ) {
			$url = untrailingslashit( home_url() ) . $url;
		}
		$rule = detour_match_rule( $url );
		if ( ! $rule ) {
			WP_CLI::log( 'No rule matches.' );
			return;
		}
		WP_CLI::log(
			sprintf(
				'Rule #%d (%s %s): %s -> %s%s',
				$rule['id'],
				$rule['match_type'],
				$rule['redirect_status'],
				$rule['source_pattern'],
				'' === (string) $rule['destination_url'] ? '(410 Gone)' : $rule['destination_url'],
				empty( $rule['_query'] ) ? '' : ' (+ query ?' . $rule['_query'] . ')'
			)
		);
	}
}

WP_CLI::add_command( 'detour', 'Detour_CLI_Command' );
