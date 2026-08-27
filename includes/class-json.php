<?php
/**
 * PHP's json_encode() can't tell an empty associative array from an empty
 * list — [] always wins, never {}. Anywhere a value is documented/expected
 * as a JSON *object* (an MCP inputSchema.properties map, a taxonomy→terms
 * dict, a meta_key→value map, an OpenAPI schema map, ...) and the backing
 * PHP array can legitimately end up empty at runtime, wrap it with
 * AICOM_Json::obj() before it's returned/encoded.
 *
 * This exact bug class has broken strict MCP clients (Pydantic-based
 * validators like Hermes) three separate times — tools/list schema empty
 * for a zero-parameter tool, then again per-property in compact_tools'
 * strip_descriptions() — each fixed by hand with an inline
 * `empty($x) ? new stdClass() : $x` at the one call site someone happened
 * to be looking at. Use this helper instead of repeating that inline, so
 * the fix is one function instead of N scattered copies.
 */
class AICOM_Json {

    /**
     * @param array $value
     * @return array|stdClass Same array if non-empty, otherwise an empty
     *                        stdClass so json_encode() emits {} not [].
     */
    public static function obj( array $value ) {
        return empty( $value ) ? new stdClass() : $value;
    }
}
