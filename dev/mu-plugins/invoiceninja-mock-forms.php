<?php
/**
 * Plugin Name: Invoice Ninja API mock: quotes and tags (dev/test only)
 * Description: Adds the routes the Quote Request Form uses to the Invoice Ninja mock at https://in.test.
 *
 * Scenario options:
 *   in_mock_quotes        quotes created so far (id => quote)
 *   in_mock_tags          tags created so far (id => tag)
 *   in_mock_quote_timeout 1: POST quotes saves the quote, then times out (unknown result)
 *
 * The main mock (invoiceninja-mock.php) logs every request and runs at priority 10 without
 * looking at earlier answers, so the answer worked out here at priority 5 is handed back
 * again at priority 99. Everything that isn't a quote or tag route passes through.
 *
 * Never load this on a real site: it is only mounted by dev/docker-compose.yml.
 */

defined( 'ABSPATH' ) || exit;

/**
 * The answer for the request being handled, keyed by method and URL.
 *
 * @var array<string, mixed>
 */
$GLOBALS['in_mock_forms_answer'] = array();

function in_mock_forms_reply( $body, $code = 200 ) {
	return array(
		'headers'  => array( 'x-app-version' => get_option( 'in_mock_version', '5.13.52' ) ),
		'body'     => wp_json_encode( $body ),
		'response' => array( 'code' => $code, 'message' => 'Mock' ),
		'cookies'  => array(),
		'filename' => null,
	);
}

function in_mock_forms_route( $method, $url, $body, $token ) {
	$base = 'https://in.test/api/v1/';
	if ( strpos( $url, $base ) !== 0 || get_option( 'in_mock_mode' ) === 'down' ) {
		return false;
	}
	$route = substr( $url, strlen( $base ) );
	$path  = (string) strtok( $route, '?' );
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
	$data = json_decode( (string) $body, true ) ?: array();

	if ( $path === 'tags' && $method === 'POST' ) {
		if ( strpos( (string) $token, 'nonadmin' ) === 0 ) {
			return in_mock_forms_reply( array( 'message' => 'This action is unauthorized.' ), 403 );
		}
		$tags = get_option( 'in_mock_tags', array() );
		$name = (string) ( $data['name'] ?? '' );
		if ( $name === '' || strpos( $name, ',' ) !== false || ( $data['entity_type'] ?? '' ) !== 'quote' ) {
			return in_mock_forms_reply( array( 'message' => 'The given data was invalid.', 'errors' => array( 'name' => array( 'Tag names may not contain commas.' ) ) ), 422 );
		}
		foreach ( $tags as $tag ) {
			if ( strtolower( $tag['name'] ) === strtolower( $name ) ) {
				return in_mock_forms_reply( array( 'message' => 'The given data was invalid.', 'errors' => array( 'name' => array( 'The name has already been taken.' ) ) ), 422 );
			}
		}
		$id          = 'TAG' . ( count( $tags ) + 1 );
		$tags[ $id ] = array( 'id' => $id, 'name' => $name, 'entity_type' => 'App\\Models\\Quote', 'color' => null, 'is_deleted' => false, 'archived_at' => 0 );
		update_option( 'in_mock_tags', $tags, false );
		return in_mock_forms_reply( array( 'data' => $tags[ $id ] ) );
	}

	if ( $path === 'tags' && $method === 'GET' ) {
		$filter = (string) ( $q['filter'] ?? '' );
		$found  = array_values( array_filter( get_option( 'in_mock_tags', array() ), function ( $tag ) use ( $filter ) {
			return $filter === '' || stripos( $tag['name'], $filter ) !== false;
		} ) );
		return in_mock_forms_reply( array( 'data' => $found, 'meta' => array( 'pagination' => array( 'total_pages' => 1 ) ) ) );
	}

	if ( $path === 'quotes' && $method === 'POST' ) {
		$tags = get_option( 'in_mock_tags', array() );
		foreach ( (array) ( $data['tags'] ?? array() ) as $i => $tag_id ) {
			if ( ! isset( $tags[ $tag_id ] ) ) {
				return in_mock_forms_reply( array( 'message' => 'The given data was invalid.', 'errors' => array( 'tags.' . $i => array( 'The selected tags.' . $i . ' is invalid.' ) ) ), 422 );
			}
		}
		if ( (string) ( $data['client_id'] ?? '' ) === '' ) {
			return in_mock_forms_reply( array( 'message' => 'The given data was invalid.', 'errors' => array( 'client_id' => array( 'The client id field is required.' ) ) ), 422 );
		}
		$quotes = get_option( 'in_mock_quotes', array() );
		$n      = count( $quotes ) + 1;
		$quote  = array(
			'id'            => 'QUOTE' . $n,
			'number'        => sprintf( 'Q%04d', $n ),
			'client_id'     => (string) $data['client_id'],
			'status_id'     => '1',
			'public_notes'  => (string) ( $data['public_notes'] ?? '' ),
			'private_notes' => (string) ( $data['private_notes'] ?? '' ),
			'line_items'    => $data['line_items'] ?? array(),
			'tags'          => array_map( function ( $id ) use ( $tags ) {
				return array( 'id' => $id, 'name' => $tags[ $id ]['name'] );
			}, (array) ( $data['tags'] ?? array() ) ),
			'tag_ids'       => array_values( (array) ( $data['tags'] ?? array() ) ),
			'is_deleted'    => false,
			'created_at'    => time(),
		);
		$quotes[ $quote['id'] ] = $quote;
		update_option( 'in_mock_quotes', $quotes, false );
		if ( get_option( 'in_mock_quote_timeout' ) ) {
			// Invoice Ninja saved it, but the response never arrived.
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 10000 milliseconds' );
		}
		return in_mock_forms_reply( array( 'data' => $quote ) );
	}

	if ( $path === 'quotes' && $method === 'GET' ) {
		// Like the real API: client_id narrows to a client; `filter` doesn't search private_notes.
		$found = array_values( array_filter( get_option( 'in_mock_quotes', array() ), function ( $quote ) use ( $q ) {
			return ! isset( $q['client_id'] ) || $quote['client_id'] === $q['client_id'];
		} ) );
		usort( $found, function ( $a, $b ) {
			return strnatcmp( $b['id'], $a['id'] );
		} );
		return in_mock_forms_reply( array( 'data' => $found, 'meta' => array( 'pagination' => array( 'total_pages' => 1 ) ) ) );
	}

	if ( preg_match( '#^quotes/([A-Za-z0-9]+)$#', $path, $m ) && $method === 'GET' ) {
		$quotes = get_option( 'in_mock_quotes', array() );
		return isset( $quotes[ $m[1] ] ) ? in_mock_forms_reply( array( 'data' => $quotes[ $m[1] ] ) ) : in_mock_forms_reply( array( 'message' => 'Not found' ), 404 );
	}

	return false;
}

add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	$method = isset( $args['method'] ) ? $args['method'] : 'GET';
	$body   = isset( $args['body'] ) && is_string( $args['body'] ) ? $args['body'] : null;
	$token  = isset( $args['headers']['X-API-TOKEN'] ) ? $args['headers']['X-API-TOKEN'] : '';
	$answer = in_mock_forms_route( $method, $url, $body, $token );
	if ( false === $answer ) {
		return $pre;
	}
	$GLOBALS['in_mock_forms_answer'][ $method . ' ' . $url ] = $answer;
	return $answer;
}, 5, 3 );

add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	$key = ( isset( $args['method'] ) ? $args['method'] : 'GET' ) . ' ' . $url;
	if ( ! array_key_exists( $key, $GLOBALS['in_mock_forms_answer'] ) ) {
		return $pre;
	}
	$answer = $GLOBALS['in_mock_forms_answer'][ $key ];
	unset( $GLOBALS['in_mock_forms_answer'][ $key ] );
	return $answer;
}, 99, 3 );
