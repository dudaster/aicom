<?php
/**
 * Notices: short plain-text messages for a person or an AI to read (PROTOCOL §12).
 *
 * A tool result may carry `notices: [{level, text, items?, code?}]`. The router cleans them to the protocol's caps and
 * appends them to the MCP text content, so every AI client reads them; the AICOMBase executor hands them over as the
 * result's `notices` when AICOMBase lists that feature. Events carry the same shape (AICOM_Base_Events).
 * Texts are data: no HTML, no links.
 */
defined( 'ABSPATH' ) || exit;

class AICOM_Notices {

    const MAX       = 10;
    const TEXT_MAX  = 500;
    const ITEMS_MAX = 50;
    const ITEM_MAX  = 200;
    const LEVELS    = [ 'info', 'warn', 'error' ];

    public static function make( string $level, string $code, string $text, array $items = [] ): array {
        $n = [ 'level' => $level, 'code' => $code, 'text' => $text ];
        if ( $items ) {
            $n['items'] = array_values( $items );
        }
        return $n;
    }

    /** The well-formed part of a notices list, capped; anything malformed is dropped. */
    public static function clean( $list ): array {
        $out = [];
        foreach ( is_array( $list ) ? $list : [] as $n ) {
            if ( count( $out ) >= self::MAX ) {
                break;
            }
            $n = is_array( $n ) ? self::clean_one( $n ) : null;
            if ( $n ) {
                $out[] = $n;
            }
        }
        return $out;
    }

    /** One notice cleaned to {level, text, items?, code?}, or null when it has no level or text. */
    public static function clean_one( array $n ): ?array {
        $level = (string) ( $n['level'] ?? '' );
        $text  = self::plain( $n['text'] ?? '', self::TEXT_MAX );
        if ( ! in_array( $level, self::LEVELS, true ) || $text === '' ) {
            return null;
        }
        $out   = [ 'level' => $level, 'text' => $text ];
        $items = array_values( array_filter( array_map( static fn( $i ) => is_scalar( $i ) ? self::plain( $i, self::ITEM_MAX ) : '', (array) ( $n['items'] ?? [] ) ), 'strlen' ) );
        if ( $items ) {
            $out['items'] = array_slice( $items, 0, self::ITEMS_MAX );
        }
        $code = (string) ( $n['code'] ?? '' );
        if ( preg_match( '/^[A-Za-z0-9_.:-]{1,60}$/', $code ) ) {
            $out['code'] = $code;
        }
        return $out;
    }

    /** Notices as lines of text, for the MCP content block. */
    public static function as_text( array $notices ): string {
        $lines = [];
        foreach ( $notices as $n ) {
            $lines[] = strtoupper( $n['level'] ) . ': ' . $n['text'];
            foreach ( (array) ( $n['items'] ?? [] ) as $i ) {
                $lines[] = '- ' . $i;
            }
        }
        return implode( "\n", $lines );
    }

    public static function plain( $s, int $max ): string {
        $s = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $s ) ) ?? '' );
        return mb_substr( $s, 0, $max );
    }
}
