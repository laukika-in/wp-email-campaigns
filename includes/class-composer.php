<?php
namespace WPEC;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Composer {
    public function init() {
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
    }

    private function manage_cap() {
        $cap = 'manage_options';
        if ( class_exists(__NAMESPACE__ . '\\Helpers') ) {
            if ( method_exists( Helpers::class, 'manage_cap' ) ) {
                $cap = Helpers::manage_cap();
            } elseif ( method_exists( Helpers::class, 'cap' ) ) {
                $cap = Helpers::cap();
            }
        }
        return $cap;
    }

    public function admin_menu() {
        add_submenu_page(
            'edit.php?post_type=email_campaign',
            __( 'Compose', 'wp-email-campaigns' ),
            __( 'Compose', 'wp-email-campaigns' ),
            $this->manage_cap(),
            'wpec-compose',
            [ $this, 'render_page' ],
        );
    }

    public function render_page() {
        echo '<div class="wrap"><h1>' . esc_html__( 'Compose', 'wp-email-campaigns' ) . '</h1>';

        echo '<div class="wpec-card" id="wpec-composer-app">';
        echo '<p class="description" style="margin-top:0;">' . esc_html__( 'Draft your message and send a quick test.', 'wp-email-campaigns' ) . '</p>';

        // Subject
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row"><label for="wpec-campaign-subject">' . esc_html__( 'Subject', 'wp-email-campaigns' ) . '</label></th>';
        echo '<td><input type="text" id="wpec-campaign-subject" class="regular-text" placeholder="' . esc_attr__( 'Subject line…', 'wp-email-campaigns' ) . '"></td></tr>';

        // Optional From (kept simple for now)
        echo '<tr><th scope="row"><label for="wpec-from-email">' . esc_html__( 'From (optional)', 'wp-email-campaigns' ) . '</label></th>';
        echo '<td><input type="text" id="wpec-from-name" class="regular-text" placeholder="' . esc_attr__( 'From name', 'wp-email-campaigns' ) . '" style="max-width:260px;margin-right:8px;">';
        echo '<input type="email" id="wpec-from-email" class="regular-text" placeholder="' . esc_attr__( 'From email', 'wp-email-campaigns' ) . '" style="max-width:260px;"></td></tr>';
        echo '</tbody></table>';

        // Body (TinyMCE)
        echo '<h2 style="margin-top:24px;">' . esc_html__( 'Message', 'wp-email-campaigns' ) . '</h2>';
        ob_start();
        wp_editor(
            '',                              // initial content
            'wpec-campaign-body',            // editor id
            [
                'textarea_name' => 'wpec_campaign_body',
                'textarea_rows' => 14,
                'media_buttons' => false,
                'tinymce'       => true,
                'quicktags'     => true,
            ]
        );
        echo ob_get_clean();

        // Test send
        echo '<h2 style="margin-top:24px;">' . esc_html__( 'Send a test', 'wp-email-campaigns' ) . '</h2>';
        echo '<input type="email" id="wpec-test-to" class="regular-text" placeholder="' . esc_attr__( 'test@example.com', 'wp-email-campaigns' ) . '" style="max-width:280px;margin-right:8px;">';
        echo '<button class="button button-primary" id="wpec-send-test">' . esc_html__( 'Send test', 'wp-email-campaigns' ) . '</button> ';
        echo '<span class="wpec-loader" id="wpec-send-loader" style="display:none"></span>';

        echo '</div>'; // /card
        echo '</div>'; // /wrap
    }
}
