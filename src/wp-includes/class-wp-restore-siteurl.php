<?php
/**
 * Handles the restore siteurl operation when the user updates either 'home' or 'siteurl' options
 * and wants to revert it back.
 * 
 * @since 5.3.0
 * 
 * @link https://core.trac.wordpress.org/ticket/47954
 */
class WP_Restore_Siteurl {
    /**
     * Expiry time of the created transients for Restore Siteurl.
     * 
     * @since 5.3.0
     * @var string
     */
    const RESTORE_TRANSIENTS_EXPIRY_IN_SECONDS = 1800;

    /**
     * Restore key to authenticate the restore siteurl request.
     *
     * @since 5.3.0
     * @var string
     */
    private $restore_key = null;

    /**
     * Is the restore siteurl email sent to the user.
     *
     * @since 5.3.0
     * @var boolean
     */
    private $is_restore_siteurl_email_sent = false;

    /**
     * Old 'home' option value.
     *
     * @since 5.3.0
     * @var string
     */
    private $old_home_value = null;

    /**
     * Hook the functions.
     * 
     * @since 5.3.0
     */
    public function __construct() {
        add_action( 'registered_taxonomy', array( $this, 'perform_siteurl_restore' ) );
        add_action( 'updated_option', array( $this, 'setup_siteurl_restore' ), 10, 3 );
        add_action( 'admin_init', array( $this, 'restore_siteurl_success_notice' ) );
    }

    /**
     * Generates a restore key.
     * 
     * @since 5.3.0
     *
     * @return string $restore_key A random string to authenticate the restore siteurl request. 
     */
    private function generate_restore_key() {
        if ( null === $this->restore_key ) {
            $this->restore_key = wp_generate_password( 16, false );
        }

        return $this->restore_key;
    }

    /**
     * Listen to updates for 'home' and 'siteurl' options.
     * 
     * This function performs the following tasks when either 'home' and 'siteurl' options are updated:
     * 1. Caches the old 'home' value if it's the option updated.
     * 2. Creates a 'siteurl_restore_key' transient that will be used to authenticate the restore operation.
     * 3. Backups the old 'home' or 'siteurl' values.
     * 4. Sends an email to the admin with the restore link. 
     * 
     * @since 5.3.0
     * 
     * @param string $option    Name of the updated option.
     * @param string $old_value The old option value.
     * @param string $value     The new option value.
     */
    public function setup_siteurl_restore( $option, $old_value, $value ) {
        if ( 'siteurl' === $option || 'home' === $option ) {
            // Prevent Restore Siteurl protocol if the update is the restoration.
            $backup_value = get_transient( "old_{$option}" );
            if ( $value === $backup_value ) {
                return;
            }

            // Backup the old 'home' option value.
            if ( 'home' === $option ) {
                $this->old_home = $old_value;
            }

            $restore_key_transient = get_transient( 'siteurl_restore_key' );
            $set_restore_key_transient = false;
            if ( $this->generate_restore_key() != $restore_key_transient ) {
                // Create a restore key transient.
                $set_restore_key_transient = set_transient( 'siteurl_restore_key', $this->generate_restore_key(), self::RESTORE_TRANSIENTS_EXPIRY_IN_SECONDS );
            }

            // Keep a backup of the old `siteurl` and `home`.
            delete_transient( "old_{$option}" );
            $set_backup_transient = set_transient( "old_{$option}", $old_value, self::RESTORE_TRANSIENTS_EXPIRY_IN_SECONDS );
            
            if ( $set_restore_key_transient && $set_backup_transient ) {
                $this->send_restore_link_email_to_admin();
            }
        }
    }

    /**
     * Send restore link email to admin.
     * 
     * @since 5.3.0
     */
    private function send_restore_link_email_to_admin() {
        if ( ! $this->is_restore_siteurl_email_sent ) {
            $restore_link = add_query_arg( [
                'srk' => $this->generate_restore_key(),
            ], $this->get_old_home_value() );

            $admin_email = get_option( 'admin_email' );
            /**
             * Filters the subject of the restore siteurl email.
             *
             * @since 5.3.0
             *
             * @param string $email_subject The subject of the restore siteurl email.
             */
            $email_subject = apply_filters( 'restore_siteurl_email_subject', __( 'Your WordPress site url was changed.' ) );
            /**
             * Filters the content body of the restore siteurl email.
             *
             * @since 5.3.0
             *
             * @param string $email_content The content body of the restore siteurl email.
             */
            $email_content = apply_filters(
                'restore_siteurl_email_message', 
                __( 'You can undo this change by clicking this link ###restore_link###' )
            );

            // Replace placeholder with actual restore link.
            $email_content = str_replace( '###restore_link###', $restore_link, $email_content );

            $email = wp_mail( $admin_email, $email_subject, $email_content );

            if ( $email ) {
                $this->is_restore_siteurl_email_sent = true;
            }

            /**
             * Fires after the attempt to send restore link email to admin.
             *
             * @since 5.3.0
             *
             * @param boolean $email Whether the email is sent successfully.
             */
            do_action( 'send_restore_link_email', $email );
        }
    }

    /**
     * Get the old 'home' option value. 
     * 
     * If `$this->old_home` is null then the 'home' option wasn't updated.
     * Get the 'home' value from options table if it's not backed up.
     * 
     * @since 5.3.0
     *
     * @return string $old_home_value Old 'home' option value.
     */
    public function get_old_home_value() {
        if ( null === $this->old_home_value ) {
            $this->old_home_value = get_option( 'home' );
        }

        return esc_url( $this->old_home_value );
    }

    /**
     * Perform the siteurl restore operation.
     * 
     * If the provided restore key is invalid, the process is terminated using `wp_die()`.
     * Else if the restore key is valid, then it will perform the restore siteurl operation.
     * 
     * If the restore siteurl operation is a success, it will perform the following.
     * 1. Delete the backup transients.
     * 2. Create a success-tracker transient named 'siteurl_restore_success'.
     * 3. Redirect the user to the old admin dashboard url.
     * 
     * @since 5.3.0
     */
    public function perform_siteurl_restore() {
        if ( isset( $_GET['srk'] ) && ! empty( $_GET['srk'] ) ) {
            $restore_key = get_transient( 'siteurl_restore_key' );

            if ( $_GET['srk'] === $restore_key ) {
                $old_siteurl = get_transient( 'old_siteurl' );
                $old_home	 = get_transient( 'old_home' );

                $is_restore_success = false;

                if ( $old_siteurl ) {
                    $update_siteurl = update_option( 'siteurl', $old_siteurl );

                    if ( $update_siteurl ) {
                        delete_transient( 'old_siteurl' );

                        $is_restore_success = true;
                    }
                }

                if ( $old_home ) {
                    $update_home = update_option( 'home', $old_home );

                    if ( $update_home ) {
                        delete_transient( 'old_home' );

                        $is_restore_success = true;
                    }
                }

                if ( $is_restore_success ) {
                    delete_transient( 'siteurl_restore_key' );

                    // Track success.
                    set_transient( 'siteurl_restore_success', '1', 300 );

                    $this->success_redirect();
                }
            }
            else {
                wp_die( __( 'Restore key is invalid.' ) );
                exit;
            }
        }
    }

    /**
     * Redirect the user to admin dashboard with restore siteurl success $_GET param.
     *
     * @since 5.3.0
     */
    protected function success_redirect() {
        $restored_site_admin_url = trailingslashit( get_option( 'home' ) ) . 'wp-admin';

        // Success redirect url.
        $success_redirect_url = add_query_arg( 'srsuccess', '1', $restored_site_admin_url );

        wp_redirect( $success_redirect_url );
        exit;
    }

    /**
     * Display success notice if the restore siteurl operation is a success.
     *
     * @since 5.3.0
     */
    public function restore_siteurl_success_notice() {
        if ( isset( $_GET['srsuccess'] ) && '1' === $_GET['srsuccess'] ) {
            $restore_success = get_transient( 'siteurl_restore_success' );

            if ( '1' === $restore_success ) {
                add_action( 'admin_notices', [ $this, 'restore_siteurl_success_notice__success' ] );

                delete_transient( 'siteurl_restore_success' );
            }
        }
    }

    /**
     * Restore siteurl success notice.
     *
     * @since 5.3.0
     */
    public function restore_siteurl_success_notice__success() {
        $class = 'notice notice-success';
        $message = __( 'Your WordPress site url was successfully restored.' );

        printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) ); 
    }
}