<?php
/**
 * Abstract base for all tool modules.
 * Each module registers its tools into AICOM_Tool_Registry on boot.
 */
abstract class AICOM_Module_Base {

    /**
     * Module slug — must match Module_Detector keys.
     */
    abstract public function get_module_name(): string;

    /**
     * Register all module tools into AICOM_Tool_Registry.
     * Called once during plugin boot.
     */
    abstract public function register_tools(): void;

    /**
     * Shared helper: return a standardised success result.
     * $data   = response payload
     * $meta   = ['target_type', 'target_id', 'summary'] for audit logger
     */
    protected function ok( array $data, array $meta = [] ): array {
        return array_merge( $data, [ '_meta' => $meta ] );
    }

    /**
     * Shared helper: return a standardised error result.
     * status_code must be one of the SPEC standard status strings.
     */
    protected function err( string $code, string $message, string $status_code = 'error', int $http_status = 400 ): array {
        return [
            'error'       => [ 'code' => $code, 'message' => $message, 'status_code' => $status_code ],
            'http_status' => $http_status,
        ];
    }

    /**
     * Validate required string argument. Returns sanitized value or null.
     */
    protected function require_string( array $args, string $key ): ?string {
        $val = $args[ $key ] ?? null;
        if ( $val === null || $val === '' ) {
            return null;
        }
        if ( ! is_scalar( $val ) ) {
            return null; // Reject arrays/objects — prevents silent "Array" string coercion
        }
        return (string) $val;
    }

    /**
     * Validate required int argument. Returns int or null.
     */
    protected function require_int( array $args, string $key ): ?int {
        $val = $args[ $key ] ?? null;
        if ( $val === null ) {
            return null;
        }
        $int = filter_var( $val, FILTER_VALIDATE_INT );
        return $int === false ? null : (int) $int;
    }

    /**
     * Register one tool with standard module/dependency filled in.
     */
    protected function register( string $tool_name, array $meta ): void {
        $meta['module']     = $this->get_module_name();
        $meta['dependency'] = $meta['dependency'] ?? null;
        AICOM_Tool_Registry::register( $tool_name, $meta );
    }
}
