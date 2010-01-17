<?php

/**
 * MailSMTP Class
 *
 * This class provides SMTP functionality for sent mail through Habari.
 *
 * @todo Document methods
 * @todo Provide an undo popup link like in gmail.
 **/

class MailSMTP extends Plugin
{
	/**
	 * function info
	 * Returns information about this plugin
	 * @return array Plugin info array
	 **/
	function info()
	{
		return array (
			'name' => 'Mail SMTP',
			'url' => 'http://www.benhutchins.com/',
			'author' => 'Benjamin Hutchins',
			'authorurl' => 'http://www.benhutchins.com/',
			'version' => '1.0',
			'description' => 'Allows you to use an SMTP server to send mail through Habari.',
			'license' => 'Apache License 2.0',
		);
	}

	/**
	 * function action_plugin_activation
	 * adds the "deleted" status type to the poststatus table
	 * when this plugin is activated.
	**/
	public function action_plugin_activation( $file )
	{
		if ( realpath( $file ) == __FILE__ ) {
			Options::set( 'mailsmtp__hostname', 'smtp.gmail.com' );
			Options::set( 'mailsmtp__port', 465 );
			Options::set( 'mailsmtp__ssl', true );

			Options::set( 'mailsmtp__auth', false );
			Options::set( 'mailsmtp__username', '' );
			Options::set( 'mailsmtp__password', '' );

			Options::set( 'mailsmtp__from', '' );
		}
	}

	/**
	 * This function is executed when the filter "before_post_delete" is
	 * called just before a post is to be deleted.
	 * This filter should return a boolean value to indicate whether
	 * the post should be deleted or not.
	 * @param Boolean Whether to delete the post or not
	 * @param Post The post object to potentially delete
	 * @return Boolean Whether to delete the post or not
	 **/
	function filter_send_mail( &$handled, $mail )
	{
		require( dirname( __FILE__ ) . '/mail.php' );

		// Start SMTP object
		$smtp = new Mail_SMTP( array (
			'host' => ( Options::get('mailsmtp__ssl') ? 'ssl://' : '' ) . Options::get('mailsmtp__hostname'),
			'port' => Options::get( 'mailsmtp__port' ),
			'auth' => (bool) Options::get( 'mailsmtp__auth' ),
			'username' => Options::get( 'mailsmtp__username' ),
			'password' => Options::get( 'mailsmtp__password' )
		) );

		// Make header array
		$headers = array_merge( $mail['headers'], array(
			'To' => $mail['to'],
			'Subject' => $mail['subject']
		) );

		// Do we have a from?
		$from = Options::get( 'mailsmtp__from' );
		if ( !empty( $from ) ) {
			$headers['From'] = $from;
		}

		// Send!
		if ( $smtp->send( $mail['to'], $headers, $mail['message'] ) ) {
			$handled = true;
			return true;
		}
		else {
			$handled = false;
			return false;
		}
	}

	public function filter_plugin_config( $actions, $plugin_id )
	{
		if ( $plugin_id == $this->plugin_id() ) {
			$actions[]= _t( 'Configure' );
		}
		return $actions;
	}

	public function action_plugin_ui( $plugin_id, $action )
	{
		if ( $plugin_id == $this->plugin_id() ) {
			switch ( $action ) {
				case _t( 'Configure' ):
					$ui = new FormUI( strtolower( get_class( $this ) ) );
					$ui->append( 'text', 'hostname', 'option:mailsmtp__hostname', _t( 'SMTP Host:' ) );
					$ui->append( 'text', 'port', 'option:mailsmtp__port', _t( 'SMTP Port:' ) );
					$ui->append( 'checkbox', 'ssl', 'option:mailsmtp__ssl', _t( 'Use SSL?:' ) );
					$ui->append( 'checkbox', 'auth', 'option:mailsmtp__auth', _t( 'Use SMTP Auth:' ) );
					$ui->append( 'text', 'username', 'option:mailsmtp__username', _t( 'Auth Username:' ) );
					$ui->append( 'text', 'password', 'option:mailsmtp__password', _t( 'Auth Password:' ) );
					$ui->append( 'text', 'from', 'option:mailsmtp__from', _t( 'From Address (optional, can be \'Hello &lt;hello@example.com&gt;\')') );
					$ui->append( 'submit', 'save', 'Save' );
					$ui->on_success( array( $this, 'updated_config' ) );
					$ui->out();
					break;
			}

		}
	}

	public function updated_config( $ui )
	{
		$ui->save();
		return false;
	}
}

?>
