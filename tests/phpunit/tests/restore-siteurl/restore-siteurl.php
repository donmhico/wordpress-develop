<?php
/**
 * @group restore-siteurl
 */
class Tests_Restore_Siteurl extends WP_UnitTestCase {

    /**
     * Tracker of how many times the action `send_restore_link_email` is invoked.
     *
     * @var integer
     */
    private $test_opt_ctr = 1;

    /**
     * Restore siteurl email should only be sent once.
     * 
     * In this test, 'test_opt' option is added with the value of `$this->test_opt_ctr`.
     * Then function `$this->change_test_opt_value()` that increments the value of `test_opt`
     * is hooked in `send_restore_link_email` action.
     * 
     * Since it is expected that the email will only be sent once, then `send_restore_link_email`
     * action should only be invoked once. Hence the expected value of `test_opt` value should
     * be 1.
     */
    public function test_restore_link_email_should_only_be_sent_once() {
        update_option( 'test_opt', $this->test_opt_ctr );

        add_action( 'send_restore_link_email', array( $this, 'change_test_opt_value' ) );

        update_option( 'home', 'http://wp.org' );
        update_option( 'site_url', 'http://wp.org' );

        $test_opt = get_option( 'test_opt' );

        $this->assertEquals( '1', $test_opt );
    }

    public function change_test_opt_value( $email ) {
        update_option( 'test_opt', $this->test_opt_ctr );
        $this->test_opt_ctr += 1;
    }

    public function test_updating_home_option_should_create_backup_transient() {
        update_option( 'home', 'http://wp.org' );
        $a = get_option( 'home' );
        $actual = get_transient( 'old_home' );
        $this->assertNotFalse( $actual );
    }

    public function test_updating_siteurl_option_should_create_backup_transient() {
        update_option( 'siteurl', 'http://wp.org' );
        $a = get_option( 'siteurl' );
        $actual = get_transient( 'old_siteurl' );
        $this->assertNotFalse( $actual );
    }

    public function test_restore_key_should_not_change_when_both_options_are_updated() {
        update_option( 'home', 'http://wp.org' );

        $wp_restore_siteurl = wp_restore_siteurl();

        $reflection = new ReflectionClass( $wp_restore_siteurl );
        $method     = $reflection->getMethod( 'generate_restore_key' );
        $method->setAccessible( true );

        $restore_key           = $method->invokeArgs( $wp_restore_siteurl, array() );
        $transient_restore_key = get_transient( 'siteurl_restore_key' );

        update_option( 'siteurl', 'http://wp.org' );

        $this->assertEquals( $restore_key, $transient_restore_key );
    }

    /**
     * @expectedException        WPDieException
     * @expectedExceptionMessage Restore key is invalid.
     */
    public function test_invalid_restore_key_should_be_blocked() {
        update_option( 'home', 'http://wp.org' );
        update_option( 'siteurl', 'http://wp.org' );

        // Use invalid restore key.
        $_GET['srk'] = 'this-is-invalid.';

        $wp_restore_siteurl = wp_restore_siteurl();
        $wp_restore_siteurl->perform_siteurl_restore();
    }

    public function test_success_perform_siteurl_restore() {
        update_option( 'home', 'http://wp.org' );
        update_option( 'siteurl', 'http://wp.org' );

        // Use the correct restore keys.
        $_GET = array();
        $_GET['srk'] = get_transient( 'siteurl_restore_key' );

        // Test that success restore will perform redirection.
        $wp_restore_siteurl = $this->getMockBuilder( 'WP_Restore_Siteurl' )
                                ->setMethods( array( 'success_redirect' ) )
                                ->getMock();
        $wp_restore_siteurl->expects( $this->once() )
            ->method( 'success_redirect' );

        $wp_restore_siteurl->perform_siteurl_restore();

        $this->assertFalse( get_transient( 'old_home' ) );
        $this->assertFalse( get_transient( 'old_siteurl' ) );
        $this->assertFalse( get_transient( 'siteurl_restore_key' ) );
        
        $this->assertEquals( '1', get_transient( 'siteurl_restore_success' ) );
    }

    public function test_restore_siteurl_success_notice() {
        update_option( 'home', 'http://wp.org' );
        update_option( 'siteurl', 'http://wp.org' );

        // Use the correct restore keys.
        $_GET = array();
        $_GET['srk'] = get_transient( 'siteurl_restore_key' );

        // Perform the siteurl restore.
        $wp_restore_siteurl = $this->getMockBuilder( 'WP_Restore_Siteurl' )
                                ->setMethods( array( 'success_redirect' ) )
                                ->getMock();
        $wp_restore_siteurl->perform_siteurl_restore();

        // Simulate click to success url.
        $_GET = array();
        $_GET['srsuccess'] = '1';
        $wp_restore_siteurl->restore_siteurl_success_notice();

        $is_success_notice_hooked = has_action( 'admin_notices', [ $wp_restore_siteurl, 'restore_siteurl_success_notice__success'] );

        $this->assertEquals( 10, $is_success_notice_hooked );

        $success_transient = get_transient( 'siteurl_restore_success' );

        $this->assertFalse( $success_transient );
    }
}