<?php

/**
 * Mail interface. Defines the interface for implementing
 * mailers under the PEAR hierarchy, and provides supporting functions
 * useful in multiple mailer backends.
 *
 * @access public
 * @version $Revision: 1.20 $
 * @package Mail
 */
class Mail
{
	/**
	 * Line terminator used for separating header lines.
	 * @var string
	 */
	var $sep = "\r\n";

	/**
	 * Implements Mail::send() function using php's built-in mail()
	 * command.
	 *
	 * @param mixed $recipients Either a comma-seperated list of recipients
	 *			  (RFC822 compliant), or an array of recipients,
	 *			  each RFC822 valid. This may contain recipients not
	 *			  specified in the headers, for Bcc:, resending
	 *			  messages, etc.
	 *
	 * @param array $headers The array of headers to send with the mail, in an
	 *			  associative array, where the array key is the
	 *			  header name (ie, 'Subject'), and the array value
	 *			  is the header value (ie, 'test'). The header
	 *			  produced from those values would be 'Subject:
	 *			  test'.
	 *
	 * @param string $body The full text of the message body, including any
	 *			   Mime parts, etc.
	 *
	 * @return mixed Returns true on success, or a Error
	 *			   containing a descriptive error message on
	 *			   failure.
	 *
	 * @access public
	 * @deprecated use Mail_mail::send instead
	 */
	public function send($recipients, $headers, $body)
	{
		if (!is_array($headers)) {
			throw Error::raise('$headers must be an array');
		}

		$result = $this->_sanitizeHeaders($headers);
		if (is_a($result, 'Error')) {
			return $result;
		}

		// if we're passed an array of recipients, implode it.
		if (is_array($recipients)) {
			$recipients = implode(', ', $recipients);
		}

		// get the Subject out of the headers array so that we can
		// pass it as a seperate argument to mail().
		$subject = '';
		if (isset($headers['Subject'])) {
			$subject = $headers['Subject'];
			unset($headers['Subject']);
		}

		// flatten the headers out.
		list(, $text_headers) = Mail::prepareHeaders($headers);

		return mail($recipients, $subject, $body, $text_headers);
	}

	/**
	 * Sanitize an array of mail headers by removing any additional header
	 * strings present in a legitimate header's value.  The goal of this
	 * filter is to prevent mail injection attacks.
	 *
	 * @param array $headers The associative array of headers to sanitize.
	 *
	 * @access protected
	 */
	protected function _sanitizeHeaders(&$headers)
	{
		foreach ($headers as $key => $value) {
			$headers[$key] =
				preg_replace('=((<CR>|<LF>|0x0A/%0A|0x0D/%0D|\\n|\\r)\S).*=i',
							 null, $value);
		}
	}

	/**
	 * Take an array of mail headers and return a string containing
	 * text usable in sending a message.
	 *
	 * @param array $headers The array of headers to prepare, in an associative
	 *			  array, where the array key is the header name (ie,
	 *			  'Subject'), and the array value is the header
	 *			  value (ie, 'test'). The header produced from those
	 *			  values would be 'Subject: test'.
	 *
	 * @return mixed Returns false if it encounters a bad address,
	 *			   otherwise returns an array containing two
	 *			   elements: Any From: address found in the headers,
	 *			   and the plain text version of the headers.
	 * @access protected
	 */
	protected function prepareHeaders($headers)
	{
		$lines = array();
		$from = null;

		foreach ($headers as $key => $value) {
			if (strcasecmp($key, 'From') === 0) {
				$parser = new Mail_RFC822();
				$addresses = $parser->parseAddressList($value, 'localhost', false);
				if (is_a($addresses, 'Error')) {
					return $addresses;
				}

				$from = $addresses[0]->mailbox . '@' . $addresses[0]->host;

				// Reject envelope From: addresses with spaces.
				if (strstr($from, ' ')) {
					return false;
				}

				$lines[] = $key . ': ' . $value;
			} elseif (strcasecmp($key, 'Received') === 0) {
				$received = array();
				if (is_array($value)) {
					foreach ($value as $line) {
						$received[] = $key . ': ' . $line;
					}
				}
				else {
					$received[] = $key . ': ' . $value;
				}
				// Put Received: headers at the top.  Spam detectors often
				// flag messages with Received: headers after the Subject:
				// as spam.
				$lines = array_merge($received, $lines);
			} else {
				// If $value is an array (i.e., a list of addresses), convert
				// it to a comma-delimited string of its elements (addresses).
				if (is_array($value)) {
					$value = implode(', ', $value);
				}
				$lines[] = $key . ': ' . $value;
			}
		}

		return array($from, join($this->sep, $lines));
	}

	/**
	 * Take a set of recipients and parse them, returning an array of
	 * bare addresses (forward paths) that can be passed to sendmail
	 * or an smtp server with the rcpt to: command.
	 *
	 * @param mixed Either a comma-seperated list of recipients
	 *			  (RFC822 compliant), or an array of recipients,
	 *			  each RFC822 valid.
	 *
	 * @return mixed An array of forward paths (bare addresses) or a Error
	 *			   object if the address list could not be parsed.
	 * @access protected
	 */
	protected function parseRecipients($recipients)
	{
		// if we're passed an array, assume addresses are valid and
		// implode them before parsing.
		if (is_array($recipients)) {
			$recipients = implode(', ', $recipients);
		}

		// Parse recipients, leaving out all personal info. This is
		// for smtp recipients, etc. All relevant personal information
		// should already be in the headers.
		$addresses = Mail_RFC822::parseAddressList($recipients, 'localhost', false);

		// If parseAddressList() returned a Error object, just return it.
		if (is_a($addresses, 'Error')) {
			return $addresses;
		}

		$recipients = array();
		if (is_array($addresses)) {
			foreach ($addresses as $ob) {
				$recipients[] = $ob->mailbox . '@' . $ob->host;
			}
		}

		return $recipients;
	}

}

/**
 * SMTP implementation of the PEAR Mail interface. Requires the SMTP class.
 * @access public
 * @package Mail
 * @version $Revision: 1.33 $
 */
class Mail_SMTP extends Mail
{

	/** Error: Failed to create a SMTP object */
	const ERROR_CREATE = 10000;
	
	/** Error: Failed to connect to SMTP server */
	const ERROR_CONNECT = 10001;
	
	/** Error: SMTP authentication failure */
	const ERROR_AUTH = 10002;
	
	/** Error: No From: address has been provided */
	const ERROR_FROM = 10003;

	/** Error: Failed to set sender */
	const ERROR_SENDER = 10004;

	/** Error: Failed to add recipient */
	const ERROR_RECIPIENT = 10005;

	/** Error: Failed to send data */
	const ERROR_DATA = 10006;

	/**
	 * SMTP connection object.
	 *
	 * @var object
	 * @access private
	 */
	private $_smtp = null;

	/**
	 * The list of service extension parameters to pass to the SMTP
	 * mailFrom() command.
	 * @var array
	 */
	private $_extparams = array();

	/**
	 * The SMTP host to connect to.
	 * @var string
	 */
	var $host = 'localhost';

	/**
	 * The port the SMTP server is on.
	 * @var integer
	 */
	var $port = 25;

	/**
	 * Should SMTP authentication be used?
	 *
	 * This value may be set to true, false or the name of a specific
	 * authentication method.
	 *
	 * If the value is set to true, the SMTP package will attempt to use
	 * the best authentication method advertised by the remote SMTP server.
	 *
	 * @var mixed
	 */
	var $auth = false;

	/**
	 * The username to use if the SMTP server requires authentication.
	 * @var string
	 */
	var $username = '';

	/**
	 * The password to use if the SMTP server requires authentication.
	 * @var string
	 */
	var $password = '';

	/**
	 * Hostname or domain that will be sent to the remote SMTP server in the
	 * HELO / EHLO message.
	 *
	 * @var string
	 */
	var $localhost = 'localhost';

	/**
	 * SMTP connection timeout value.  NULL indicates no timeout.
	 *
	 * @var integer
	 */
	var $timeout = null;

	/**
	 * Turn on SMTP debugging?
	 *
	 * @var boolean $debug
	 */
	var $debug = false;

	/**
	 * Indicates whether or not the SMTP connection should persist over
	 * multiple calls to the send() method.
	 *
	 * @var boolean
	 */
	var $persist = false;

	/**
	 * Use SMTP command pipelining (specified in RFC 2920) if the SMTP server
	 * supports it. This speeds up delivery over high-latency connections. By
	 * default, use the default value supplied by SMTP.
	 * @var bool
	 */
	var $pipelining;

	/**
	 * Constructor.
	 *
	 * Instantiates a new Mail_smtp:: object based on the parameters
	 * passed in. It looks for the following parameters:
	 *	 host		The server to connect to. Defaults to localhost.
	 *	 port		The port to connect to. Defaults to 25.
	 *	 auth		SMTP authentication.  Defaults to none.
	 *	 username	The username to use for SMTP auth. No default.
	 *	 password	The password to use for SMTP auth. No default.
	 *	 localhost   The local hostname / domain. Defaults to localhost.
	 *	 timeout	 The SMTP connection timeout. Defaults to none.
	 *	 verp		Whether to use VERP or not. Defaults to false.
	 *				 DEPRECATED as of 1.2.0 (use setMailParams()).
	 *	 debug	   Activate SMTP debug mode? Defaults to false.
	 *	 persist	 Should the SMTP connection persist?
	 *	 pipelining  Use SMTP command pipelining
	 *
	 * If a parameter is present in the $params array, it replaces the
	 * default.
	 *
	 * @param array Hash containing any parameters different from the
	 *			  defaults.
	 * @access public
	 */
	public function __construct($params)
	{
		if (isset($params['host'])) $this->host = $params['host'];
		if (isset($params['port'])) $this->port = $params['port'];
		if (isset($params['auth'])) $this->auth = $params['auth'];
		if (isset($params['username'])) $this->username = $params['username'];
		if (isset($params['password'])) $this->password = $params['password'];
		if (isset($params['localhost'])) $this->localhost = $params['localhost'];
		if (isset($params['timeout'])) $this->timeout = $params['timeout'];
		if (isset($params['debug'])) $this->debug = (bool)$params['debug'];
		if (isset($params['persist'])) $this->persist = (bool)$params['persist'];
		if (isset($params['pipelining'])) $this->pipelining = (bool)$params['pipelining'];

		// Deprecated options
		if (isset($params['verp'])) {
			$this->addServiceExtensionParameter('XVERP', is_bool($params['verp']) ? null : $params['verp']);
		}

		/**
		 * Destructor implementation to ensure that we disconnect from any
		 * potentially-alive persistent SMTP connections.
		 */
		register_shutdown_function( array(&$this, 'disconnect') );
	}

	/**
	 * Implements Mail::send() function using SMTP.
	 *
	 * @param mixed $recipients Either a comma-seperated list of recipients
	 *			  (RFC822 compliant), or an array of recipients,
	 *			  each RFC822 valid. This may contain recipients not
	 *			  specified in the headers, for Bcc:, resending
	 *			  messages, etc.
	 *
	 * @param array $headers The array of headers to send with the mail, in an
	 *			  associative array, where the array key is the
	 *			  header name (e.g., 'Subject'), and the array value
	 *			  is the header value (e.g., 'test'). The header
	 *			  produced from those values would be 'Subject:
	 *			  test'.
	 *
	 * @param string $body The full text of the message body, including any
	 *			   MIME parts, etc.
	 *
	 * @return mixed Returns true on success, or a Error
	 *			   containing a descriptive error message on
	 *			   failure.
	 * @access public
	 */
	function send($recipients, $headers, $body)
	{
		/* If we don't already have an SMTP object, create one. */
		$result = &$this->getSMTPObject();
		if (Error::is_error($result)) {
			return $result;
		}

		if (!is_array($headers)) {
			throw Error::raise('$headers must be an array');
		}

		$this->_sanitizeHeaders($headers);

		$headerElements = $this->prepareHeaders($headers);
		if (is_a($headerElements, 'Error')) {
			$this->_smtp->rset();
			return $headerElements;
		}
		list($from, $textHeaders) = $headerElements;

		/* Since few MTAs are going to allow this header to be forged
		 * unless it's in the MAIL FROM: exchange, we'll use
		 * Return-Path instead of From: if it's set. */
		if (!empty($headers['Return-Path'])) {
			$from = $headers['Return-Path'];
		}

		if (!isset($from)) {
			$this->_smtp->rset();
			throw Error::raise('No From: address has been provided', self::ERROR_FROM);
		}

		$params = null;
		if (!empty($this->_extparams)) {
			foreach ($this->_extparams as $key => $val) {
				$params .= ' ' . $key . (is_null($val) ? '' : '=' . $val);
			}
		}
		if (Error::is_error($res = $this->_smtp->mailFrom($from, ltrim($params)))) {
			$error = $this->_error("Failed to set sender: $from", $res);
			$this->_smtp->rset();
			throw Error::raise($error, self::ERROR_SENDER);
		}

		$recipients = $this->parseRecipients($recipients);
		if (is_a($recipients, 'Error')) {
			$this->_smtp->rset();
			return $recipients;
		}

		foreach ($recipients as $recipient) {
			$res = $this->_smtp->rcptTo($recipient);
			if (is_a($res, 'Error')) {
				$error = $this->_error("Failed to add recipient: $recipient", $res);
				$this->_smtp->rset();
				throw Error::raise($error, self::ERROR_RECIPIENT);
			}
		}

		/* Send the message's headers and the body as SMTP data. */
		$res = $this->_smtp->data($textHeaders . "\r\n\r\n" . $body);
		if (is_a($res, 'Error')) {
			$error = $this->_error('Failed to send data', $res);
			$this->_smtp->rset();
			throw Error::raise($error, self::ERROR_DATA);
		}

		/* If persistent connections are disabled, destroy our SMTP object. */
		if ($this->persist === false) {
			$this->disconnect();
		}

		return true;
	}

	/**
	 * Connect to the SMTP server by instantiating a SMTP object.
	 *
	 * @return mixed Returns a reference to the SMTP object on success, or
	 *			   a Error containing a descriptive error message on
	 *			   failure.
	 *
	 * @since  1.2.0
	 * @access public
	 */
	public function &getSMTPObject()
	{
		if (is_object($this->_smtp) !== false) {
			return $this->_smtp;
		}

		$this->_smtp = &new SMTP($this->host, $this->port, $this->localhost);

		/* If we still don't have an SMTP object at this point, fail. */
		if (is_object($this->_smtp) === false) {
			throw Error::raise('Failed to create a SMTP object', self::ERROR_CREATE);
		}

		/* Configure the SMTP connection. */
		if ($this->debug) {
			$this->_smtp->setDebug(true);
		}

		/* Attempt to connect to the configured SMTP server. */
		if (Error::is_error($res = $this->_smtp->connect($this->timeout))) {
			$error = $this->_error('Failed to connect to ' . $this->host . ':' . $this->port, $res);
			throw Error::raise($error, self::ERROR_CONNECT);
		}

		/* Attempt to authenticate if authentication has been enabled. */
		if ($this->auth) {
			$method = is_string($this->auth) ? $this->auth : '';

			if (Error::is_error($res = $this->_smtp->auth($this->username,
														$this->password,
														$method))) {
				$error = $this->_error("$method authentication failure",
									   $res);
				$this->_smtp->rset();
				throw Error::raise($error, self::ERROR_AUTH);
			}
		}

		return $this->_smtp;
	}

	/**
	 * Add parameter associated with a SMTP service extension.
	 *
	 * @param string Extension keyword.
	 * @param string Any value the keyword needs.
	 *
	 * @since 1.2.0
	 * @access public
	 */
	public function addServiceExtensionParameter($keyword, $value = null)
	{
		$this->_extparams[$keyword] = $value;
	}

	/**
	 * Disconnect and destroy the current SMTP connection.
	 *
	 * @return boolean True if the SMTP connection no longer exists.
	 *
	 * @since  1.1.9
	 * @access public
	 */
	public function disconnect()
	{
		/* If we have an SMTP object, disconnect and destroy it. */
		if (is_object($this->_smtp) && $this->_smtp->disconnect()) {
			$this->_smtp = null;
		}

		/* We are disconnected if we no longer have an SMTP object. */
		return ($this->_smtp === null);
	}

	/**
	 * Build a standardized string describing the current SMTP error.
	 *
	 * @param string $text  Custom string describing the error context.
	 * @param object $error Reference to the current Error object.
	 *
	 * @return string	   A string describing the current SMTP error.
	 *
	 * @since  1.1.7
	 * @access private
	 */
	private function _error($text, &$error)
	{
		/* Split the SMTP response into a code and a response string. */
		list($code, $response) = $this->_smtp->getResponse();

		/* Build our standardized error string. */
		return $text
			. ' [SMTP: ' . $error->getMessage()
			. " (code: $code, response: $response)]";
	}

}
