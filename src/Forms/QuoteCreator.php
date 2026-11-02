<?php
/**
 * Sends saved leads to Invoice Ninja as draft quotes.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Forms;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Api\ErrorKind;
use InvoiceNinja\Api\HttpClient;
use InvoiceNinja\Core\LogLevel;
use InvoiceNinja\Core\RetryPolicy;
use InvoiceNinja\Plugin;

/**
 * One background job per lead (invoiceninja_lead_to_quote):
 * 1. find the client by email, or create one (an existing client is never changed);
 * 2. create the quote tags if needed (cached ids);
 * 3. create a draft quote with the message as public_notes, an optional line item and the
 *    tags. After any result we can't be sure of, the client's recent quotes are searched for
 *    the lead's marker first, so a lead never becomes two quotes.
 * When Invoice Ninja is down the job is retried with backoff; after the last attempt, or
 * when Invoice Ninja refuses the data, the lead is marked failed with a reason.
 */
final class QuoteCreator {

	public const HOOK = 'invoiceninja_lead_to_quote';

	/**
	 * Constructor.
	 *
	 * @param Plugin     $plugin Plugin.
	 * @param Leads      $leads  Leads.
	 * @param TagManager $tags   Tags.
	 */
	public function __construct( private Plugin $plugin, private Leads $leads, private TagManager $tags ) {}

	/**
	 * Queue a lead.
	 *
	 * @param int $lead_id Lead id.
	 * @param int $delay   Seconds.
	 */
	public function queue( int $lead_id, int $delay = 0 ): void {
		$this->plugin->queue->push( self::HOOK, array( 'lead_id' => $lead_id ), $delay, true );
	}

	/**
	 * Background job.
	 *
	 * @param array<string, mixed> $payload {lead_id}.
	 * @param int                  $attempt Attempt number.
	 * @throws ApiException To let the queue retry.
	 */
	public function handle( array $payload, int $attempt = 1 ): void {
		$lead = $this->leads->find( (int) ( $payload['lead_id'] ?? 0 ) );
		if ( null === $lead || 'queued' !== $lead['status'] ) {
			return; // Sent already, deleted, or waiting for an admin.
		}
		if ( 'none' === $this->plugin->credentials->source() || ! $this->plugin->environment->writesAllowed() ) {
			// Not connected, or a staging copy: wait without using up attempts.
			$this->leads->update( (int) $lead['id'], array( 'reason' => 'paused' ) );
			$this->plugin->queue->push( self::HOOK, array( 'lead_id' => (int) $lead['id'] ), 900, false, $attempt );
			return;
		}
		try {
			$this->send( $lead );
		} catch ( ApiException $e ) {
			if ( $e->kind->retryable() && $attempt < RetryPolicy::maxAttempts() ) {
				$this->leads->update(
					(int) $lead['id'],
					array(
						'reason'   => 'retrying:' . $e->kind->value,
						'attempts' => $attempt,
					)
				);
				throw $e;
			}
			$this->fail( $lead, $e );
		}
	}

	/**
	 * Send a lead now (admin Retry). Failed and held leads are queued again first.
	 *
	 * @param int $lead_id Lead id.
	 * @return array<string, mixed>|null The lead afterwards.
	 */
	public function retryNow( int $lead_id ): ?array {
		$lead = $this->leads->find( $lead_id );
		if ( null === $lead ) {
			return null;
		}
		if ( in_array( $lead['status'], array( 'failed', 'spam' ), true ) ) {
			$this->leads->transition(
				$lead_id,
				array( 'failed', 'spam' ),
				'queued',
				array(
					'reason'   => '',
					'attempts' => 0,
				)
			);
		}
		$lead = (array) $this->leads->find( $lead_id );
		if ( 'queued' !== $lead['status'] ) {
			return $lead;
		}
		try {
			$this->send( $lead );
		} catch ( ApiException $e ) {
			if ( $e->kind->retryable() ) {
				$this->leads->update( $lead_id, array( 'reason' => 'retrying:' . $e->kind->value ) );
				$this->queue( $lead_id, 60 );
			} else {
				$this->fail( $lead, $e );
			}
		}
		return $this->leads->find( $lead_id );
	}

	/**
	 * Create the client (if needed), the tags and the quote.
	 *
	 * @param array<string, mixed> $lead Lead.
	 * @throws ApiException When something failed.
	 */
	private function send( array $lead ): void {
		$id = (int) $lead['id'];
		// One request or job at a time per lead.
		$claim = 'lead_claim_' . $id;
		$at    = (int) $this->plugin->options->get( $claim, 0 );
		if ( $at > 0 && $at < time() - 300 ) {
			$this->plugin->options->delete( $claim ); // The request holding it died.
		}
		if ( ! $this->plugin->options->addAtomic( $claim, (string) time() ) ) {
			return;
		}
		try {
			$this->create( $lead );
		} finally {
			$this->plugin->options->delete( $claim );
		}
	}

	/**
	 * The steps, after the claim.
	 *
	 * @param array<string, mixed> $lead Lead.
	 * @throws ApiException When something failed.
	 */
	private function create( array $lead ): void {
		$id     = (int) $lead['id'];
		$lead   = (array) $this->leads->find( $id ); // Fresh, now that we hold the claim.
		$fields = (array) $lead['fields'];
		if ( 'queued' !== ( $lead['status'] ?? '' ) ) {
			return;
		}
		$client    = $this->plugin->api();
		$client_id = (string) $lead['client_id'];
		if ( '' === $client_id ) {
			$found     = $this->plugin->clients->findOrCreate(
				$client,
				(string) $lead['email'],
				array(
					'first_name' => (string) ( $fields['first_name'] ?? '' ),
					'last_name'  => (string) ( $fields['last_name'] ?? '' ),
					'phone'      => (string) ( $fields['phone'] ?? '' ),
				)
			);
			$client_id = $found['client_id'];
			if ( '' === $client_id ) {
				throw new ApiException( ErrorKind::InvalidResponse, 'Invoice Ninja returned no client id' );
			}
			$this->leads->update(
				$id,
				array(
					'client_id'  => $client_id,
					'contact_id' => $found['contact_id'],
					'matched'    => ! $found['created'],
				)
			);
		}
		$marker = QuotePayload::marker( $this->plugin->secrets->siteCode(), $id, (string) $lead['marker'] );
		$quote  = $lead['posted'] ? $this->findExisting( $client, $marker, $client_id ) : null;
		if ( null === $quote ) {
			$quote = $this->createQuote( $client, $lead, $client_id, $marker );
		}
		$this->created( $lead, $quote );
	}

	/**
	 * POST /quotes, then look for the quote after an unknown result.
	 *
	 * @param HttpClient           $client    API client.
	 * @param array<string, mixed> $lead      Lead.
	 * @param string               $client_id Client id.
	 * @param string               $marker    Marker.
	 * @return array<string, mixed>
	 * @throws ApiException When it failed.
	 */
	private function createQuote( HttpClient $client, array $lead, string $client_id, string $marker ): array {
		$fields  = (array) $lead['fields'];
		$product = null;
		$chosen  = (string) ( $fields['product'] ?? '' );
		if ( '' !== $chosen && FormConfig::NOT_SURE !== $chosen ) {
			$info = $this->plugin->cart->product( $chosen );
			if ( null !== $info ) {
				$product = array(
					'product_key' => $info['product_key'],
					'notes'       => $info['notes'],
					'price'       => $info['price'],
				);
			}
		}
		$state = $this->plugin->state->get();
		/**
		 * Tag names for a lead's quote (commas are removed; at most 20).
		 *
		 * @since 2.0.0
		 * @param list<string>         $names Names from Settings > Forms.
		 * @param array<string, mixed> $lead  Lead.
		 */
		$names   = QuotePayload::tagNames( (array) apply_filters( 'invoiceninja_quote_tags', QuotePayload::tagNames( (string) $this->plugin->options->setting( 'forms.tags' ) ), $lead ) );
		$tag_ids = $this->tags->ids( $client, $names, (string) ( $state['version'] ?? '' ) );
		$notes   = QuotePayload::privateNotes( $marker, $fields, (array) $lead['consents'], (string) wp_parse_url( (string) home_url(), PHP_URL_HOST ) );
		$body    = QuotePayload::quoteBody( $client_id, (string) ( $fields['message'] ?? '' ), $notes, $product, $tag_ids );
		/**
		 * The body of the draft quote created for a lead.
		 *
		 * @since 2.0.0
		 * @param array<string, mixed> $body Body for POST /quotes.
		 * @param array<string, mixed> $lead Lead.
		 */
		$body = (array) apply_filters( 'invoiceninja_quote_request_body', $body, $lead );
		$this->leads->update( (int) $lead['id'], array( 'posted' => true ) );
		try {
			return $client->post( 'quotes', $body, array(), 10.0 )->data();
		} catch ( ApiException $e ) {
			if ( in_array( $e->kind, array( ErrorKind::Timeout, ErrorKind::Duplicate, ErrorKind::Server, ErrorKind::Transport ), true ) ) {
				// It may exist even though we didn't hear back.
				try {
					$found = $this->findExisting( $client, $marker, $client_id );
				} catch ( ApiException $ignored ) {
					$found = null;
				}
				if ( null !== $found ) {
					return $found;
				}
			}
			if ( ErrorKind::Validation === $e->kind && $tag_ids && self::tagError( $e->errors ) ) {
				// A cached tag was deleted in Invoice Ninja: create the tags again, once.
				$this->tags->forget();
				$body['tags'] = $this->tags->ids( $client, $names, (string) ( $state['version'] ?? '' ) );
				if ( ! $body['tags'] ) {
					unset( $body['tags'] );
				}
				return $client->post( 'quotes', $body, array(), 10.0 )->data();
			}
			throw $e;
		}
	}

	/**
	 * Whether a 422 is about the tags.
	 *
	 * @param array<string, mixed> $errors Validation errors.
	 */
	public static function tagError( array $errors ): bool {
		foreach ( array_keys( $errors ) as $key ) {
			if ( str_starts_with( (string) $key, 'tags' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * This lead's quote, if Invoice Ninja already has it.
	 *
	 * @param HttpClient $client    API client.
	 * @param string     $marker    Marker.
	 * @param string     $client_id Client id.
	 * @return array<string, mixed>|null
	 * @throws ApiException When Invoice Ninja can't be asked.
	 */
	private function findExisting( HttpClient $client, string $marker, string $client_id ): ?array {
		$quotes = $client->get(
			'quotes',
			array(
				'client_id' => $client_id,
				'sort'      => 'id|desc',
				'per_page'  => 50,
			),
			8.0
		)->data();
		return QuotePayload::findByMarker( $quotes, $marker, $client_id );
	}

	/**
	 * Record the quote.
	 *
	 * @param array<string, mixed> $lead  Lead.
	 * @param array<string, mixed> $quote Quote.
	 * @throws ApiException When the reply has no quote id.
	 */
	private function created( array $lead, array $quote ): void {
		$quote_id = (string) ( $quote['id'] ?? '' );
		if ( '' === $quote_id ) {
			throw new ApiException( ErrorKind::InvalidResponse, 'Invoice Ninja returned no quote id' );
		}
		$ok = $this->leads->transition(
			(int) $lead['id'],
			array( 'queued' ),
			'in_ninja',
			array(
				'quote_id'     => $quote_id,
				'quote_number' => (string) ( $quote['number'] ?? '' ),
				'quote_status' => QuotePayload::status( $quote ),
				'reason'       => '',
				'checked_at'   => time(),
			)
		);
		if ( ! $ok ) {
			return;
		}
		$this->plugin->logger->log( LogLevel::Info, 'forms', sprintf( 'Quote request %d: draft quote %s created', (int) $lead['id'], (string) ( $quote['number'] ?? $quote_id ) ), array(), 'lead', (string) $lead['id'] );
		/**
		 * Fires when a lead's draft quote was created in Invoice Ninja.
		 *
		 * @since 2.0.0
		 * @param array $lead  Lead.
		 * @param array $quote Quote from Invoice Ninja.
		 */
		do_action( 'invoiceninja_lead_quoted', (array) $this->leads->find( (int) $lead['id'] ), $quote );
	}

	/**
	 * Mark a lead failed.
	 *
	 * @param array<string, mixed> $lead Lead.
	 * @param ApiException         $e    Error.
	 */
	private function fail( array $lead, ApiException $e ): void {
		$reason = $e->kind->title() . '. ' . $e->kind->fix();
		if ( ErrorKind::Validation === $e->kind && $e->errors ) {
			$errors  = $e->errors;
			$first   = reset( $errors );
			$reason .= ' ' . ( is_array( $first ) ? (string) reset( $first ) : (string) $first );
		}
		$this->leads->transition(
			(int) $lead['id'],
			array( 'queued' ),
			'failed',
			array( 'reason' => trim( $reason ) )
		);
		$this->plugin->logger->log( LogLevel::Error, 'forms', sprintf( "Quote request %d couldn't be sent to Invoice Ninja: %s", (int) $lead['id'], $e->kind->title() ), array( 'technical' => $e->technical ), 'lead', (string) $lead['id'] );
	}

	/**
	 * Daily: re-check open quotes (draft / sent), oldest check first, and re-queue leads
	 * whose job was lost.
	 *
	 * @param int $limit Most quotes to fetch.
	 */
	public function daily( int $limit = 100 ): void {
		foreach ( $this->leads->idsWithStatus( 'queued', 200 ) as $id ) {
			$lead = $this->leads->find( $id );
			if ( null !== $lead && strtotime( $lead['created_at'] . ' UTC' ) < time() - 600 ) {
				$this->queue( $id );
			}
		}
		if ( 'connected' !== $this->plugin->state->get()['status'] ) {
			return;
		}
		$open = array();
		foreach ( $this->leads->idsWithStatus( 'in_ninja', 1000 ) as $id ) {
			$lead = $this->leads->find( $id );
			if ( null !== $lead && in_array( $lead['quote_status'], QuotePayload::OPEN, true ) ) {
				$open[] = $lead;
			}
		}
		usort( $open, static fn( array $a, array $b ): int => $a['checked_at'] <=> $b['checked_at'] );
		$client = $this->plugin->api();
		foreach ( array_slice( $open, 0, $limit ) as $lead ) {
			try {
				$quote = $client->get( 'quotes/' . rawurlencode( (string) $lead['quote_id'] ), array(), 5.0 )->data();
				$this->setQuoteStatus( (int) $lead['id'], QuotePayload::status( $quote ) );
			} catch ( ApiException $e ) {
				if ( ErrorKind::NotFound === $e->kind ) {
					$this->setQuoteStatus( (int) $lead['id'], 'deleted' );
					continue;
				}
				if ( $e->kind->retryable() || $e->kind->breaksConnection() ) {
					return; // Try again tomorrow.
				}
			}
		}
	}

	/**
	 * Update a lead's quote status.
	 *
	 * @param int    $lead_id Lead id.
	 * @param string $status  Quote status.
	 */
	public function setQuoteStatus( int $lead_id, string $status ): void {
		$this->leads->update(
			$lead_id,
			array(
				'quote_status' => $status,
				'checked_at'   => time(),
			)
		);
	}

	/**
	 * A quote event from the Webhooks module (the entity is re-fetched already).
	 *
	 * @param array<string, mixed> $quote Quote.
	 * @param bool                 $approved From the quote-approved event.
	 */
	public function quoteEvent( array $quote, bool $approved = false ): void {
		$lead = $this->leads->findByQuote( (string) ( $quote['id'] ?? '' ) );
		if ( null === $lead ) {
			return;
		}
		$status = QuotePayload::status( $quote );
		if ( $approved && ! in_array( $status, array( 'converted', 'deleted' ), true ) ) {
			$status = 'approved';
		}
		$this->setQuoteStatus( (int) $lead['id'], $status );
		if ( 'approved' === $status ) {
			$this->plugin->logger->log( LogLevel::Info, 'forms', sprintf( 'Quote request %d: quote %s approved', (int) $lead['id'], (string) $lead['quote_number'] ), array(), 'lead', (string) $lead['id'] );
		}
	}
}
