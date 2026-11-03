<?php

/**
 * @package Invoice Ninja
 */

namespace InvoiceNinja\Api;

class ProfileApi extends BaseApi
{
    /** The company settings the 1.x screens read; nothing else (no keys or mail secrets) is kept. */
    const SETTINGS = [ 'name', 'website', 'primary_color', 'currency_id', 'country_id', 'show_currency_code' ];

    /** Logos larger than this aren't imported. */
    const MAX_LOGO_BYTES = 2097152;

    /** Image types a logo may be, by what its bytes say (never SVG or HTML). */
    const LOGO_TYPES = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    public static function load()
    {
        if ( ! $response = self::sendRequest( 'companies/current', 'POST' ) ) {
            return false;
        }

        $decoded = json_decode( $response );
        $response = $decoded->data ?? null;

        if ( ! $response ) {
            return false;
        }

        if ( ! empty( $response->settings->company_logo ) && is_string( $response->settings->company_logo ) ) {
            self::saveLogo( $response->settings->company_logo );
        }

        if ( $static_response = self::sendRequest( 'statics' ) ) {
            $static_response = json_decode( $static_response );

            if ( ! empty( $static_response->currencies ) ) {
                $currency_map = [];
                foreach ( $static_response->currencies as $currency ) {
                    $currency_map[$currency->id] = $currency;
                }
                update_option( 'invoiceninja_currencies', wp_json_encode( $currency_map) );
            }

            if ( ! empty( $static_response->countries ) ) {
                $country_map = [];
                foreach ( $static_response->countries as $country ) {
                    $obj = new \stdClass;
                    $obj->swap_currency_symbol = $country->swap_currency_symbol;
                    $obj->thousand_separator = $country->thousand_separator;
                    $obj->decimal_separator = $country->decimal_separator;
                    $country_map[$country->id] = $obj;
                }
                update_option( 'invoiceninja_countries', wp_json_encode( $country_map) );
            }
        }

        return self::reduce( $response );
    }

    /**
     * The company record reduced to what the 1.x code reads (the invoiceninja_profile option).
     *
     * @param object|array|null $company Company record from Invoice Ninja (or a stored profile).
     * @return \stdClass|null
     */
    public static function reduce( $company )
    {
        if ( is_array( $company ) ) {
            $company = json_decode( (string) wp_json_encode( $company ) );
        }

        if ( ! is_object( $company ) ) {
            return null;
        }

        $settings = isset( $company->settings ) && is_object( $company->settings ) ? $company->settings : new \stdClass();
        $kept     = new \stdClass();

        foreach ( self::SETTINGS as $field ) {
            $kept->$field = isset( $settings->$field ) && is_scalar( $settings->$field ) ? $settings->$field : '';
        }

        $kept->primary_color = (string) sanitize_hex_color( trim( (string) $kept->primary_color ) );

        $profile                  = new \stdClass();
        $profile->track_inventory = ! empty( $company->track_inventory );
        $profile->settings        = $kept;

        return $profile;
    }

    /**
     * Import the company logo into the media library: fetched without following redirects
     * (and from a private address only when it's the allowed Invoice Ninja host), and kept
     * only when the bytes are a PNG, JPEG, GIF or WebP image.
     *
     * @return int The attachment id, or 0 when the logo wasn't imported.
     */
    public static function saveLogo( $logo_url )
    {
        $logo_url = esc_url_raw( trim( (string) $logo_url ), [ 'http', 'https' ] );

        if ( '' === $logo_url ) {
            return 0;
        }

        $args = [
            'timeout'             => 15,
            'redirection'         => 0,
            'limit_response_size' => self::MAX_LOGO_BYTES,
        ];

        $own = false;
        if ( \InvoiceNinja\Plugin::booted() ) {
            $base = self::getBaseUrl();
            $own  = strtolower( (string) wp_parse_url( $logo_url, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( $base, PHP_URL_HOST ) )
                && \InvoiceNinja\Plugin::instance()->state->allowsPrivate( $base );
        }

        // The allowed private Invoice Ninja host may be on the LAN; everything else must be public.
        $result = $own ? wp_remote_get( $logo_url, $args ) : wp_safe_remote_get( $logo_url, $args );

        if ( is_wp_error( $result ) || 200 !== (int) wp_remote_retrieve_response_code( $result ) ) {
            return 0;
        }

        $body = (string) wp_remote_retrieve_body( $result );
        $mime = self::imageType( $body );

        if ( '' === $mime ) {
            return 0;
        }

        $filename = 'invoiceninja_plugin.' . self::LOGO_TYPES[ $mime ];
        $upload   = wp_upload_bits( $filename, null, $body );

        if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
            return 0;
        }

        $check = wp_check_filetype_and_ext( $upload['file'], $filename );
        if ( empty( $check['type'] ) || $check['type'] !== $mime ) {
            wp_delete_file( $upload['file'] );

            return 0;
        }

        // Replace the logo imported earlier only once the new one is safely saved.
        $attachments = get_posts(
            [
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => 5,
                'fields'         => 'ids',
                's'              => 'invoiceninja_plugin',
            ]
        );

        foreach ( (array) $attachments as $attachment_id ) {
            wp_delete_attachment( (int) $attachment_id, true );
        }

        $attachment_id = wp_insert_attachment(
            [
                'post_mime_type' => $mime,
                'post_title'     => sanitize_file_name( $filename ),
                'post_content'   => '',
                'post_status'    => 'inherit',
            ],
            $upload['file']
        );

        return is_wp_error( $attachment_id ) ? 0 : (int) $attachment_id;
    }

    /**
     * The image type the bytes really are, when it's one a logo may be; '' otherwise.
     */
    public static function imageType( $bytes )
    {
        $bytes = (string) $bytes;

        if ( '' === $bytes || strlen( $bytes ) >= self::MAX_LOGO_BYTES ) {
            return '';
        }

        $info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not an image is an answer, not an error.
        $mime = is_array( $info ) && isset( $info['mime'] ) ? strtolower( (string) $info['mime'] ) : '';

        return isset( self::LOGO_TYPES[ $mime ] ) ? $mime : '';
    }
}
