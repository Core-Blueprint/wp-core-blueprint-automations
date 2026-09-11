<?php
/**
 * Safe uninstall boundary for Core Blueprint Automations.
 *
 * Workflow definitions are operator-authored persistent configuration. The AU1
 * baseline deliberately does not delete them merely because the plugin is
 * uninstalled. A future destructive cleanup option must be explicit, audited,
 * and independently gated before this boundary changes.
 *
 * @package CB_Automations
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/* Intentionally no destructive cleanup. */
