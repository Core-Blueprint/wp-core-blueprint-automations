<?php
declare(strict_types=1);
namespace CB\Automations\Admin;

use CB\Automations\Persistence\Schema;
use CB\Automations\Support\Requirements;

defined('ABSPATH') || exit;

/** WordPress-native, explicitly authorized reauthorization; never runs on GET/boot. */
final class SchemaSetupController {
    private const ACTION = 'cb_automations_reauthorize_v1_setup';
    private static bool $registered = false;

    public static function init(): void {
        if (self::$registered) {return;}
        self::$registered = true;
        add_action('admin_post_'.self::ACTION,[self::class,'submit']);
        add_action('admin_notices',[self::class,'notice']);
    }
    public static function notice(): void {
        if (!current_user_can('activate_plugins') || !Requirements::runtime_ready() || Schema::ready()) {return;}
        echo '<div class="notice notice-warning"><p>' . esc_html__('Automations storage is not ready. Workflow editing and execution are disabled.', 'core-blueprint-automations') . '</p>';
        if (Schema::can_reauthorize()) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
            wp_nonce_field(self::ACTION);
            submit_button(__('Retry clean installation before database creation', 'core-blueprint-automations'),'secondary','submit',false);
            echo '</form>';
        }
        echo '</div>';
    }
    public static function submit(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('POST required.', 'core-blueprint-automations'),'', ['response'=>405]);
        }
        if (!current_user_can('activate_plugins') || !Requirements::runtime_ready()) {
            wp_die(esc_html__('Not authorized.', 'core-blueprint-automations'),'', ['response'=>403]);
        }
        check_admin_referer(self::ACTION);
        try { Schema::install_new(true); } catch (\Throwable) { /* Uncertain DDL remains on HOLD. */ }
        wp_safe_redirect(admin_url('plugins.php'));
        exit;
    }
}
