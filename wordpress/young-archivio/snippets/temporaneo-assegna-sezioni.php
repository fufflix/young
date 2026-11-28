<?php
// TEMPORANEO: assegna in blocco la categoria di sezione (Blog/News/Culture). Da cancellare a lavoro finito.
add_action( 'rest_api_init', function () {
	register_rest_route( 'ya-tmp/v1', '/assegna', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'callback'            => function ( WP_REST_Request $r ) {
			$cat = (int) $r->get_param( 'cat' );
			$ids = array_map( 'intval', (array) $r->get_param( 'ids' ) );
			if ( ! in_array( $cat, array( 24, 25, 26 ), true ) || count( $ids ) > 1000 ) {
				return new WP_Error( 'ya_tmp', 'Parametri non validi', array( 'status' => 400 ) );
			}
			wp_defer_term_counting( true );
			$ok = 0;
			foreach ( $ids as $id ) {
				if ( 'post' === get_post_type( $id ) && ! is_wp_error( wp_set_post_categories( $id, array( $cat ), false ) ) ) {
					$ok++;
				}
			}
			wp_defer_term_counting( false );
			return array( 'ok' => $ok, 'richiesti' => count( $ids ) );
		},
	) );
} );
