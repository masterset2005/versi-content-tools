<?php
/**
 * WP-CLI commands for both workloads.
 *
 * @package Versi_Content_Tools
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Process alt-text and excerpts via WP-CLI.
 */
class Versi_CLI extends WP_CLI_Command {

	/**
	 * Process images for alt text.
	 *
	 * ## OPTIONS
	 *
	 * <mode>
	 * : Processing mode: missing, review, or regenerate.
	 *
	 * [--cat=<cat_id>]
	 * : Category ID to filter by.
	 *
	 * [--limit=<number>]
	 * : Max images to process (0 = unlimited).
	 *
	 * ## EXAMPLES
	 *
	 *     wp versi alt missing
	 *     wp versi alt regenerate --cat=5 --limit=20
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Named args.
	 * @return void
	 */
	public function alt( $args, $assoc_args ) {
		$mode  = $args[0] ?? 'missing';
		$cat   = absint( $assoc_args['cat'] ?? 0 );
		$limit = absint( $assoc_args['limit'] ?? 0 );

		if ( ! in_array( $mode, array( 'missing', 'review', 'regenerate' ), true ) ) {
			WP_CLI::error( 'Mode must be missing, review, or regenerate.' );
		}

		$shared   = Versi_Container::get(Versi_Processor::class);
		$alt_proc = Versi_Container::get(Versi_Alt_Text_Processor::class);
		$offset   = 0;
		$batch    = absint( get_option( 'versi_batch_size', 5 ) );
		$done     = 0;

		if ( $batch < 1 ) {
			$batch = 1;
		}

		WP_CLI::line( "Processing alt-text in {$mode} mode..." );

		while ( true ) {
			$result = $shared->get_image_ids( $mode, $offset, $batch, $cat );
			$ids    = $result['ids'];

			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $id ) {
				$res = $alt_proc->process_single( $id );
				++$done;

				if ( 'error' === $res['status'] ) {
					WP_CLI::warning( "#{$id}: {$res['error']}" );
				} elseif ( 'skipped' === $res['status'] ) {
					WP_CLI::line( "#{$id}: skipped — {$res['reason']}" );
				} else {
					WP_CLI::success( "#{$id}: {$res['generated']}" );
				}

				if ( $limit > 0 && $done >= $limit ) {
					break 2;
				}
			}

			$offset += count( $ids );
		}

		WP_CLI::line( "Done. Processed {$done} images." );
	}

	/**
	 * Process posts for excerpts.
	 *
	 * ## OPTIONS
	 *
	 * <mode>
	 * : Processing mode: missing or improve.
	 *
	 * [--limit=<number>]
	 * : Max posts to process (0 = unlimited).
	 *
	 * ## EXAMPLES
	 *
	 *     wp versi excerpt missing
	 *     wp versi excerpt improve --limit=10
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Named args.
	 * @return void
	 */
	public function excerpt( $args, $assoc_args ) {
		$mode  = $args[0] ?? 'missing';
		$limit = absint( $assoc_args['limit'] ?? 0 );

		if ( ! in_array( $mode, array( 'missing', 'improve' ), true ) ) {
			WP_CLI::error( 'Mode must be missing or improve.' );
		}

		$shared    = Versi_Container::get(Versi_Processor::class);
		$excl_proc = Versi_Container::get(Versi_Excerpt_Processor::class);
		$offset    = 0;
		$batch     = absint( get_option( 'versi_batch_size', 5 ) );
		$done      = 0;

		if ( $batch < 1 ) {
			$batch = 1;
		}

		WP_CLI::line( "Processing excerpts in {$mode} mode..." );

		while ( true ) {
			$result = $shared->get_excerpt_ids( $mode, $offset, $batch );
			$ids    = $result['ids'];

			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $id ) {
				$res = $excl_proc->process_single( $id );
				++$done;

				if ( 'error' === $res['status'] ) {
					WP_CLI::warning( "#{$id}: {$res['error']}" );
				} elseif ( 'skipped' === $res['status'] ) {
					WP_CLI::line( "#{$id}: skipped — {$res['reason']}" );
				} else {
					WP_CLI::success( "#{$id}: {$res['generated']}" );
				}

				if ( $limit > 0 && $done >= $limit ) {
					break 2;
				}
			}

			$offset += count( $ids );
		}

		WP_CLI::line( "Done. Processed {$done} posts." );
	}
}

WP_CLI::add_command( 'versi', 'Versi_CLI' );
