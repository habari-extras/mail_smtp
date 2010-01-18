<?php
// +-----------------------------------------------------------------------+
// | Copyright (c) 2001-2002, Richard Heyes                                |
// | All rights reserved.                                                  |
// |                                                                       |
// | Redistribution and use in source and binary forms, with or without    |
// | modification, are permitted provided that the following conditions    |
// | are met:                                                              |
// |                                                                       |
// | o Redistributions of source code must retain the above copyright      |
// |   notice, this list of conditions and the following disclaimer.       |
// | o Redistributions in binary form must reproduce the above copyright   |
// |   notice, this list of conditions and the following disclaimer in the |
// |   documentation and/or other materials provided with the distribution.|
// | o The names of the authors may not be used to endorse or promote      |
// |   products derived from this software without specific prior written  |
// |   permission.                                                         |
// |                                                                       |
// | THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS   |
// | "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT     |
// | LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR |
// | A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT  |
// | OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, |
// | SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT      |
// | LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, |
// | DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY |
// | THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT   |
// | (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE |
// | OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.  |
// |                                                                       |
// +-----------------------------------------------------------------------+
// | Authors: Richard Heyes <richard@phpguru.org>                          |
// |          Chuck Hagenbuch <chuck@horde.org>                            |
// +-----------------------------------------------------------------------+

/**
 * RFC 822 Email address list validation Utility
 *
 * What is it?
 *
 * This class will take an address string, and parse it into it's consituent
 * parts, be that either addresses, groups, or combinations. Nested groups
 * are not supported. The structure it returns is pretty straight forward,
 * and is similar to that provided by the imap_rfc822_parse_adrlist(). Use
 * print_r() to view the structure.
 *
 * How do I use it?
 *
 * $address_string = 'My Group: "Richard" <richard@localhost> (A comment), ted@example.com (Ted Bloggs), Barney;';
 * $structure = Mail_RFC822::parseAddressList($address_string, 'example.com', true)
 * print_r($structure);
 *
 * @author  Richard Heyes <richard@phpguru.org>
 * @author  Chuck Hagenbuch <chuck@horde.org>
 * @version $Revision: 1.24 $
 * @license BSD
 * @package Mail
 */
class Mail_RFC822 {

    /**
     * The address being parsed by the RFC822 object.
     * @var string $address
     */
    var $address = '';

    /**
     * The default domain to use for unqualified addresses.
     * @var string $default_domain
     */
    var $default_domain = 'localhost';

    /**
     * Should we return a nested array showing groups, or flatten everything?
     * @var boolean $nestGroups
     */
    var $nestGroups = true;

    /**
     * Whether or not to validate atoms for non-ascii characters.
     * @var boolean $validate
     */
    var $validate = true;

    /**
     * The array of raw addresses built up as we parse.
     * @var array $addresses
     */
    var $addresses = array();

    /**
     * The final array of parsed address information that we build up.
     * @var array $structure
     */
    var $structure = array();

    /**
     * The current error message, if any.
     * @var string $error
     */
    var $error = null;

    /**
     * An internal counter/pointer.
     * @var integer $index
     */
    var $index = null;

    /**
     * The number of groups that have been found in the address list.
     * @var integer $num_groups
     * @access public
     */
    var $num_groups = 0;

    /**
     * A variable so that we can tell whether or not we're inside a
     * Mail_RFC822 object.
     * @var boolean $mailRFC822
     */
    var $mailRFC822 = true;

    /**
    * A limit after which processing stops
    * @var int $limit
    */
    var $limit = null;

    /**
     * Sets up the object. The address must either be set here or when
     * calling parseAddressList(). One or the other.
     *
     * @access public
     * @param string  $address         The address(es) to validate.
     * @param string  $default_domain  Default domain/host etc. If not supplied, will be set to localhost.
     * @param boolean $nest_groups     Whether to return the structure with groups nested for easier viewing.
     * @param boolean $validate        Whether to validate atoms. Turn this off if you need to run addresses through before encoding the personal names, for instance.
     *
     * @return object Mail_RFC822 A new Mail_RFC822 object.
     */
    function Mail_RFC822($address = null, $default_domain = null, $nest_groups = null, $validate = null, $limit = null)
    {
        if (isset($address))        $this->address        = $address;
        if (isset($default_domain)) $this->default_domain = $default_domain;
        if (isset($nest_groups))    $this->nestGroups     = $nest_groups;
        if (isset($validate))       $this->validate       = $validate;
        if (isset($limit))          $this->limit          = $limit;
    }

    /**
     * Starts the whole process. The address must either be set here
     * or when creating the object. One or the other.
     *
     * @access public
     * @param string  $address         The address(es) to validate.
     * @param string  $default_domain  Default domain/host etc.
     * @param boolean $nest_groups     Whether to return the structure with groups nested for easier viewing.
     * @param boolean $validate        Whether to validate atoms. Turn this off if you need to run addresses through before encoding the personal names, for instance.
     *
     * @return array A structured array of addresses.
     */
    function parseAddressList($address = null, $default_domain = null, $nest_groups = null, $validate = null, $limit = null)
    {
        if (!isset($this) || !isset($this->mailRFC822)) {
            $obj = new Mail_RFC822($address, $default_domain, $nest_groups, $validate, $limit);
            return $obj->parseAddressList();
        }

        if (isset($address))        $this->address        = $address;
        if (isset($default_domain)) $this->default_domain = $default_domain;
        if (isset($nest_groups))    $this->nestGroups     = $nest_groups;
        if (isset($validate))       $this->validate       = $validate;
        if (isset($limit))          $this->limit          = $limit;

        $this->structure  = array();
        $this->addresses  = array();
        $this->error      = null;
        $this->index      = null;

        // Unfold any long lines in $this->address.
        $this->address = preg_replace('/\r?\n/', "\r\n", $this->address);
        $this->address = preg_replace('/\r\n(\t| )+/', ' ', $this->address);

        while ($this->address = $this->_splitAddresses($this->address));

        if ($this->address === false || isset($this->error)) {
            require_once 'PEAR.php';
            return PEAR::raiseError($this->error);
        }

        // Validate each address individually.  If we encounter an invalid
        // address, stop iterating and return an error immediately.
        foreach ($this->addresses as $address) {
            $valid = $this->_validateAddress($address);

            if ($valid === false || isset($this->error)) {
                return Error::raise($this->error);
            }

            if (!$this->nestGroups) {
                $this->structure = array_merge($this->structure, $valid);
            } else {
                $this->structure[] = $valid;
            }
        }

        return $this->structure;
    }

    /**
     * Splits an address into separate addresses.
     *
     * @access private
     * @param string $address The addresses to split.
     * @return boolean Success or failure.
     */
    function _splitAddresses($address)
    {
        if (!empty($this->limit) && count($this->addresses) == $this->limit) {
            return '';
        }

        if ($this->_isGroup($address) && !isset($this->error)) {
            $split_char = ';';
            $is_group   = true;
        } elseif (!isset($this->error)) {
            $split_char = ',';
            $is_group   = false;
        } elseif (isset($this->error)) {
            return false;
        }

        // Split the string based on the above ten or so lines.
        $parts  = explode($split_char, $address);
        $string = $this->_splitCheck($parts, $split_char);

        // If a group...
        if ($is_group) {
            // If $string does not contain a colon outside of
            // brackets/quotes etc then something's fubar.

            // First check there's a colon at all:
            if (strpos($string, ':') === false) {
                $this->error = 'Invalid address: ' . $string;
                return false;
            }

            // Now check it's outside of brackets/quotes:
            if (!$this->_splitCheck(explode(':', $string), ':')) {
                return false;
            }

            // We must have a group at this point, so increase the counter:
            $this->num_groups++;
        }

        // $string now contains the first full address/group.
        // Add to the addresses array.
        $this->addresses[] = array(
                                   'address' => trim($string),
                                   'group'   => $is_group
                                   );

        // Remove the now stored address from the initial line, the +1
        // is to account for the explode character.
        $address = trim(substr($address, strlen($string) + 1));

        // If the next char is a comma and this was a group, then
        // there are more addresses, otherwise, if there are any more
        // chars, then there is another address.
        if ($is_group && substr($address, 0, 1) == ','){
            $address = trim(substr($address, 1));
            return $address;

        } elseif (strlen($address) > 0) {
            return $address;

        } else {
            return '';
        }

        // If you got here then something's off
        return false;
    }

    /**
     * Checks for a group at the start of the string.
     *
     * @access private
     * @param string $address The address to check.
     * @return boolean Whether or not there is a group at the start of the string.
     */
    function _isGroup($address)
    {
        // First comma not in quotes, angles or escaped:
        $parts  = explode(',', $address);
        $string = $this->_splitCheck($parts, ',');

        // Now we have the first address, we can reliably check for a
        // group by searching for a colon that's not escaped or in
        // quotes or angle brackets.
        if (count($parts = explode(':', $string)) > 1) {
            $string2 = $this->_splitCheck($parts, ':');
            return ($string2 !== $string);
        } else {
            return false;
        }
    }

    /**
     * A common function that will check an exploded string.
     *
     * @access private
     * @param array $parts The exloded string.
     * @param string $char  The char that was exploded on.
     * @return mixed False if the string contains unclosed quotes/brackets, or the string on success.
     */
    function _splitCheck($parts, $char)
    {
        $string = $parts[0];

        for ($i = 0; $i < count($parts); $i++) {
            if ($this->_hasUnclosedQuotes($string)
                || $this->_hasUnclosedBrackets($string, '<>')
                || $this->_hasUnclosedBrackets($string, '[]')
                || $this->_hasUnclosedBrackets($string, '()')
                || substr($string, -1) == '\\') {
                if (isset($parts[$i + 1])) {
                    $string = $string . $char . $parts[$i + 1];
                } else {
                    $this->error = 'Invalid address spec. Unclosed bracket or quotes';
                    return false;
                }
            } else {
                $this->index = $i;
                break;
            }
        }

        return $string;
    }

    /**
     * Checks if a string has unclosed quotes or not.
     *
     * @access private
     * @param string $string  The string to check.
     * @return boolean  True if there are unclosed quotes inside the string,
     *                  false otherwise.
     */
    function _hasUnclosedQuotes($string)
    {
        $string = trim($string);
        $iMax = strlen($string);
        $in_quote = false;
        $i = $slashes = 0;

        for (; $i < $iMax; ++$i) {
            switch ($string[$i]) {
            case '\\':
                ++$slashes;
                break;

            case '"':
                if ($slashes % 2 == 0) {
                    $in_quote = !$in_quote;
                }
                // Fall through to default action below.

            default:
                $slashes = 0;
                break;
            }
        }

        return $in_quote;
    }

    /**
     * Checks if a string has an unclosed brackets or not. IMPORTANT:
     * This function handles both angle brackets and square brackets;
     *
     * @access private
     * @param string $string The string to check.
     * @param string $chars  The characters to check for.
     * @return boolean True if there are unclosed brackets inside the string, false otherwise.
     */
    function _hasUnclosedBrackets($string, $chars)
    {
        $num_angle_start = substr_count($string, $chars[0]);
        $num_angle_end   = substr_count($string, $chars[1]);

        $this->_hasUnclosedBracketsSub($string, $num_angle_start, $chars[0]);
        $this->_hasUnclosedBracketsSub($string, $num_angle_end, $chars[1]);

        if ($num_angle_start < $num_angle_end) {
            $this->error = 'Invalid address spec. Unmatched quote or bracket (' . $chars . ')';
            return false;
        } else {
            return ($num_angle_start > $num_angle_end);
        }
    }

    /**
     * Sub function that is used only by hasUnclosedBrackets().
     *
     * @access private
     * @param string $string The string to check.
     * @param integer &$num    The number of occurences.
     * @param string $char   The character to count.
     * @return integer The number of occurences of $char in $string, adjusted for backslashes.
     */
    function _hasUnclosedBracketsSub($string, &$num, $char)
    {
        $parts = explode($char, $string);
        for ($i = 0; $i < count($parts); $i++){
            if (substr($parts[$i], -1) == '\\' || $this->_hasUnclosedQuotes($parts[$i]))
                $num--;
            if (isset($parts[$i + 1]))
                $parts[$i + 1] = $parts[$i] . $char . $parts[$i + 1];
        }

        return $num;
    }

    /**
     * Function to begin checking the address.
     *
     * @access private
     * @param string $address The address to validate.
     * @return mixed False on failure, or a structured array of address information on success.
     */
    function _validateAddress($address)
    {
        $is_group = false;
        $addresses = array();

        if ($address['group']) {
            $is_group = true;

            // Get the group part of the name
            $parts     = explode(':', $address['address']);
            $groupname = $this->_splitCheck($parts, ':');
            $structure = array();

            // And validate the group part of the name.
            if (!$this->_validatePhrase($groupname)){
                $this->error = 'Group name did not validate.';
                return false;
            } else {
                // Don't include groups if we are not nesting
                // them. This avoids returning invalid addresses.
                if ($this->nestGroups) {
                    $structure = new stdClass;
                    $structure->groupname = $groupname;
                }
            }

            $address['address'] = ltrim(substr($address['address'], strlen($groupname . ':')));
        }

        // If a group then split on comma and put into an array.
        // Otherwise, Just put the whole address in an array.
        if ($is_group) {
            while (strlen($address['address']) > 0) {
                $parts       = explode(',', $address['address']);
                $addresses[] = $this->_splitCheck($parts, ',');
                $address['address'] = trim(substr($address['address'], strlen(end($addresses) . ',')));
            }
        } else {
            $addresses[] = $address['address'];
        }

        // Check that $addresses is set, if address like this:
        // Groupname:;
        // Then errors were appearing.
        if (!count($addresses)){
            $this->error = 'Empty group.';
            return false;
        }

        // Trim the whitespace from all of the address strings.
        array_map('trim', $addresses);

        // Validate each mailbox.
        // Format could be one of: name <geezer@domain.com>
        //                         geezer@domain.com
        //                         geezer
        // ... or any other format valid by RFC 822.
        for ($i = 0; $i < count($addresses); $i++) {
            if (!$this->validateMailbox($addresses[$i])) {
                if (empty($this->error)) {
                    $this->error = 'Validation failed for: ' . $addresses[$i];
                }
                return false;
            }
        }

        // Nested format
        if ($this->nestGroups) {
            if ($is_group) {
                $structure->addresses = $addresses;
            } else {
                $structure = $addresses[0];
            }

        // Flat format
        } else {
            if ($is_group) {
                $structure = array_merge($structure, $addresses);
            } else {
                $structure = $addresses;
            }
        }

        return $structure;
    }

    /**
     * Function to validate a phrase.
     *
     * @access private
     * @param string $phrase The phrase to check.
     * @return boolean Success or failure.
     */
    function _validatePhrase($phrase)
    {
        // Splits on one or more Tab or space.
        $parts = preg_split('/[ \\x09]+/', $phrase, -1, PREG_SPLIT_NO_EMPTY);

        $phrase_parts = array();
        while (count($parts) > 0){
            $phrase_parts[] = $this->_splitCheck($parts, ' ');
            for ($i = 0; $i < $this->index + 1; $i++)
                array_shift($parts);
        }

        foreach ($phrase_parts as $part) {
            // If quoted string:
            if (substr($part, 0, 1) == '"') {
                if (!$this->_validateQuotedString($part)) {
                    return false;
                }
                continue;
            }

            // Otherwise it's an atom:
            if (!$this->_validateAtom($part)) return false;
        }

        return true;
    }

    /**
     * Function to validate an atom which from rfc822 is:
     * atom = 1*<any CHAR except specials, SPACE and CTLs>
     *
     * If validation ($this->validate) has been turned off, then
     * validateAtom() doesn't actually check anything. This is so that you
     * can split a list of addresses up before encoding personal names
     * (umlauts, etc.), for example.
     *
     * @access private
     * @param string $atom The string to check.
     * @return boolean Success or failure.
     */
    function _validateAtom($atom)
    {
        if (!$this->validate) {
            // Validation has been turned off; assume the atom is okay.
            return true;
        }

        // Check for any char from ASCII 0 - ASCII 127
        if (!preg_match('/^[\\x00-\\x7E]+$/i', $atom, $matches)) {
            return false;
        }

        // Check for specials:
        if (preg_match('/[][()<>@,;\\:". ]/', $atom)) {
            return false;
        }

        // Check for control characters (ASCII 0-31):
        if (preg_match('/[\\x00-\\x1F]+/', $atom)) {
            return false;
        }

        return true;
    }

    /**
     * Function to validate quoted string, which is:
     * quoted-string = <"> *(qtext/quoted-pair) <">
     *
     * @access private
     * @param string $qstring The string to check
     * @return boolean Success or failure.
     */
    function _validateQuotedString($qstring)
    {
        // Leading and trailing "
        $qstring = substr($qstring, 1, -1);

        // Perform check, removing quoted characters first.
        return !preg_match('/[\x0D\\\\"]/', preg_replace('/\\\\./', '', $qstring));
    }

    /**
     * Function to validate a mailbox, which is:
     * mailbox =   addr-spec         ; simple address
     *           / phrase route-addr ; name and route-addr
     *
     * @access public
     * @param string &$mailbox The string to check.
     * @return boolean Success or failure.
     */
    function validateMailbox(&$mailbox)
    {
        // A couple of defaults.
        $phrase  = '';
        $comment = '';
        $comments = array();

        // Catch any RFC822 comments and store them separately.
        $_mailbox = $mailbox;
        while (strlen(trim($_mailbox)) > 0) {
            $parts = explode('(', $_mailbox);
            $before_comment = $this->_splitCheck($parts, '(');
            if ($before_comment != $_mailbox) {
                // First char should be a (.
                $comment    = substr(str_replace($before_comment, '', $_mailbox), 1);
                $parts      = explode(')', $comment);
                $comment    = $this->_splitCheck($parts, ')');
                $comments[] = $comment;

                // +1 is for the trailing )
                $_mailbox   = substr($_mailbox, strpos($_mailbox, $comment)+strlen($comment)+1);
            } else {
                break;
            }
        }

        foreach ($comments as $comment) {
            $mailbox = str_replace("($comment)", '', $mailbox);
        }

        $mailbox = trim($mailbox);

        // Check for name + route-addr
        if (substr($mailbox, -1) == '>' && substr($mailbox, 0, 1) != '<') {
            $parts  = explode('<', $mailbox);
            $name   = $this->_splitCheck($parts, '<');

            $phrase     = trim($name);
            $route_addr = trim(substr($mailbox, strlen($name.'<'), -1));

            if ($this->_validatePhrase($phrase) === false || ($route_addr = $this->_validateRouteAddr($route_addr)) === false) {
                return false;
            }

        // Only got addr-spec
        } else {
            // First snip angle brackets if present.
            if (substr($mailbox, 0, 1) == '<' && substr($mailbox, -1) == '>') {
                $addr_spec = substr($mailbox, 1, -1);
            } else {
                $addr_spec = $mailbox;
            }

            if (($addr_spec = $this->_validateAddrSpec($addr_spec)) === false) {
                return false;
            }
        }

        // Construct the object that will be returned.
        $mbox = new stdClass();

        // Add the phrase (even if empty) and comments
        $mbox->personal = $phrase;
        $mbox->comment  = isset($comments) ? $comments : array();

        if (isset($route_addr)) {
            $mbox->mailbox = $route_addr['local_part'];
            $mbox->host    = $route_addr['domain'];
            $route_addr['adl'] !== '' ? $mbox->adl = $route_addr['adl'] : '';
        } else {
            $mbox->mailbox = $addr_spec['local_part'];
            $mbox->host    = $addr_spec['domain'];
        }

        $mailbox = $mbox;
        return true;
    }

    /**
     * This function validates a route-addr which is:
     * route-addr = "<" [route] addr-spec ">"
     *
     * Angle brackets have already been removed at the point of
     * getting to this function.
     *
     * @access private
     * @param string $route_addr The string to check.
     * @return mixed False on failure, or an array containing validated address/route information on success.
     */
    function _validateRouteAddr($route_addr)
    {
        // Check for colon.
        if (strpos($route_addr, ':') !== false) {
            $parts = explode(':', $route_addr);
            $route = $this->_splitCheck($parts, ':');
        } else {
            $route = $route_addr;
        }

        // If $route is same as $route_addr then the colon was in
        // quotes or brackets or, of course, non existent.
        if ($route === $route_addr){
            unset($route);
            $addr_spec = $route_addr;
            if (($addr_spec = $this->_validateAddrSpec($addr_spec)) === false) {
                return false;
            }
        } else {
            // Validate route part.
            if (($route = $this->_validateRoute($route)) === false) {
                return false;
            }

            $addr_spec = substr($route_addr, strlen($route . ':'));

            // Validate addr-spec part.
            if (($addr_spec = $this->_validateAddrSpec($addr_spec)) === false) {
                return false;
            }
        }

        if (isset($route)) {
            $return['adl'] = $route;
        } else {
            $return['adl'] = '';
        }

        $return = array_merge($return, $addr_spec);
        return $return;
    }

    /**
     * Function to validate a route, which is:
     * route = 1#("@" domain) ":"
     *
     * @access private
     * @param string $route The string to check.
     * @return mixed False on failure, or the validated $route on success.
     */
    function _validateRoute($route)
    {
        // Split on comma.
        $domains = explode(',', trim($route));

        foreach ($domains as $domain) {
            $domain = str_replace('@', '', trim($domain));
            if (!$this->_validateDomain($domain)) return false;
        }

        return $route;
    }

    /**
     * Function to validate a domain, though this is not quite what
     * you expect of a strict internet domain.
     *
     * domain = sub-domain *("." sub-domain)
     *
     * @access private
     * @param string $domain The string to check.
     * @return mixed False on failure, or the validated domain on success.
     */
    function _validateDomain($domain)
    {
        // Note the different use of $subdomains and $sub_domains
        $subdomains = explode('.', $domain);

        while (count($subdomains) > 0) {
            $sub_domains[] = $this->_splitCheck($subdomains, '.');
            for ($i = 0; $i < $this->index + 1; $i++)
                array_shift($subdomains);
        }

        foreach ($sub_domains as $sub_domain) {
            if (!$this->_validateSubdomain(trim($sub_domain)))
                return false;
        }

        // Managed to get here, so return input.
        return $domain;
    }

    /**
     * Function to validate a subdomain:
     *   subdomain = domain-ref / domain-literal
     *
     * @access private
     * @param string $subdomain The string to check.
     * @return boolean Success or failure.
     */
    function _validateSubdomain($subdomain)
    {
        if (preg_match('|^\[(.*)]$|', $subdomain, $arr)){
            if (!$this->_validateDliteral($arr[1])) return false;
        } else {
            if (!$this->_validateAtom($subdomain)) return false;
        }

        // Got here, so return successful.
        return true;
    }

    /**
     * Function to validate a domain literal:
     *   domain-literal =  "[" *(dtext / quoted-pair) "]"
     *
     * @access private
     * @param string $dliteral The string to check.
     * @return boolean Success or failure.
     */
    function _validateDliteral($dliteral)
    {
        return !preg_match('/(.)[][\x0D\\\\]/', $dliteral, $matches) && $matches[1] != '\\';
    }

    /**
     * Function to validate an addr-spec.
     *
     * addr-spec = local-part "@" domain
     *
     * @access private
     * @param string $addr_spec The string to check.
     * @return mixed False on failure, or the validated addr-spec on success.
     */
    function _validateAddrSpec($addr_spec)
    {
        $addr_spec = trim($addr_spec);

        // Split on @ sign if there is one.
        if (strpos($addr_spec, '@') !== false) {
            $parts      = explode('@', $addr_spec);
            $local_part = $this->_splitCheck($parts, '@');
            $domain     = substr($addr_spec, strlen($local_part . '@'));

        // No @ sign so assume the default domain.
        } else {
            $local_part = $addr_spec;
            $domain     = $this->default_domain;
        }

        if (($local_part = $this->_validateLocalPart($local_part)) === false) return false;
        if (($domain     = $this->_validateDomain($domain)) === false) return false;

        // Got here so return successful.
        return array('local_part' => $local_part, 'domain' => $domain);
    }

    /**
     * Function to validate the local part of an address:
     *   local-part = word *("." word)
     *
     * @access private
     * @param string $local_part
     * @return mixed False on failure, or the validated local part on success.
     */
    function _validateLocalPart($local_part)
    {
        $parts = explode('.', $local_part);
        $words = array();

        // Split the local_part into words.
        while (count($parts) > 0){
            $words[] = $this->_splitCheck($parts, '.');
            for ($i = 0; $i < $this->index + 1; $i++) {
                array_shift($parts);
            }
        }

        // Validate each word.
        foreach ($words as $word) {
            // If this word contains an unquoted space, it is invalid. (6.2.4)
            if (strpos($word, ' ') && $word[0] !== '"')
            {
                return false;
            }

            if ($this->_validatePhrase(trim($word)) === false) return false;
        }

        // Managed to get here, so return the input.
        return $local_part;
    }

    /**
     * Returns an approximate count of how many addresses are in the
     * given string. This is APPROXIMATE as it only splits based on a
     * comma which has no preceding backslash. Could be useful as
     * large amounts of addresses will end up producing *large*
     * structures when used with parseAddressList().
     *
     * @param  string $data Addresses to count
     * @return int          Approximate count
     */
    function approximateCount($data)
    {
        return count(preg_split('/(?<!\\\\),/', $data));
    }

    /**
     * This is a email validating function separate to the rest of the
     * class. It simply validates whether an email is of the common
     * internet form: <user>@<domain>. This can be sufficient for most
     * people. Optional stricter mode can be utilised which restricts
     * mailbox characters allowed to alphanumeric, full stop, hyphen
     * and underscore.
     *
     * @param  string  $data   Address to check
     * @param  boolean $strict Optional stricter mode
     * @return mixed           False if it fails, an indexed array
     *                         username/domain if it matches
     */
    function isValidInetAddress($data, $strict = false)
    {
        $regex = $strict ? '/^([.0-9a-z_+-]+)@(([0-9a-z-]+\.)+[0-9a-z]{2,})$/i' : '/^([*+!.&#$|\'\\%\/0-9a-z^_`{}=?~:-]+)@(([0-9a-z-]+\.)+[0-9a-z]{2,})$/i';
        if (preg_match($regex, trim($data), $matches)) {
            return array($matches[1], $matches[2]);
        } else {
            return false;
        }
    }

}

/**
 * Generalized Socket class.
 */
class Socket
{

	const READ = 1;
	const WRITE = 2;
	const ERROR = 4;

	/**
	 * Socket file pointer.
	 * @var resource $fp
	 */
	private $fp = null;

	/**
	 * Whether the socket is blocking. Defaults to true.
	 * @var boolean $blocking
	 */
	private $blocking = true;

	/**
	 * Whether the socket is persistent. Defaults to false.
	 * @var boolean $persistent
	 */
	private $persistent = false;

	/**
	 * The IP address to connect to.
	 * @var string $addr
	 */
	private $addr = '';

	/**
	 * The port number to connect to.
	 * @var integer $port
	 */
	private $port = 0;

	/**
	 * Number of seconds to wait on socket connections before assuming
	 * there's no more data. Defaults to no timeout.
	 * @var integer $timeout
	 */
	private $timeout = false;

	/**
	 * Number of bytes to read at a time in readLine() and
	 * readAll(). Defaults to 2048.
	 * @var integer $lineLength
	 */
	public $lineLength = 2048;

	/**
	 * Connect to the specified port. If called when the socket is
	 * already connected, it disconnects and connects again.
	 *
	 * @param string  $addr		IP address or host name.
	 * @param integer $port		TCP port number.
	 * @param boolean $persistent  (optional) Whether the connection is
	 *   persistent (kept open between requests
	 *   by the web server).
	 * @param integer $timeout	 (optional) How long to wait for data.
	 * @param array   $options	 See options for stream_context_create.
	 *
	 * @access public
	 *
	 * @return boolean | PEAR_Error  True on success or a PEAR_Error on failure.
	 */
	public function connect($addr, $port = 0, $persistent = null, $timeout = null, $options = null)
	{
		if (is_resource($this->fp)) {
			@fclose($this->fp);
			$this->fp = null;
		}

		if (!$addr) {
			return $this->raiseError('$addr cannot be empty');
		} elseif (strspn($addr, '.0123456789') == strlen($addr) ||
				  strstr($addr, '/') !== false) {
			$this->addr = $addr;
		} else {
			$this->addr = @gethostbyname($addr);
		}

		$this->port = $port % 65536;

		if ($persistent !== null) {
			$this->persistent = $persistent;
		}

		if ($timeout !== null) {
			$this->timeout = $timeout;
		}

		$openfunc = $this->persistent ? 'pfsockopen' : 'fsockopen';
		$errno = 0;
		$errstr = '';
		$old_track_errors = @ini_set('track_errors', 1);
		if ($options && function_exists('stream_context_create')) {
			if ($this->timeout) {
				$timeout = $this->timeout;
			} else {
				$timeout = 0;
			}
			$context = stream_context_create($options);

			// Since PHP 5 fsockopen doesn't allow context specification
			if (function_exists('stream_socket_client')) {
				$flags = $this->persistent ? STREAM_CLIENT_PERSISTENT : STREAM_CLIENT_CONNECT;
				$addr = $this->addr . ':' . $this->port;
				$fp = stream_socket_client($addr, $errno, $errstr, $timeout, $flags, $context);
			} else {
				$fp = @$openfunc($this->addr, $this->port, $errno, $errstr, $timeout, $context);
			}
		} else {
			if ($this->timeout) {
				$fp = @$openfunc($this->addr, $this->port, $errno, $errstr, $this->timeout);
			} else {
				$fp = @$openfunc($this->addr, $this->port, $errno, $errstr);
			}
		}

		if (!$fp) {
			if ($errno == 0 && isset($php_errormsg)) {
				$errstr = $php_errormsg;
			}
			@ini_set('track_errors', $old_track_errors);
			return $this->raiseError($errstr, $errno);
		}

		@ini_set('track_errors', $old_track_errors);
		$this->fp = $fp;

		return $this->setBlocking($this->blocking);
	}

	/**
	 * Disconnects from the peer, closes the socket.
	 *
	 * @access public
	 * @return mixed true on success or a PEAR_Error instance otherwise
	 */
	public function disconnect()
	{
		if (!is_resource($this->fp)) {
			return $this->raiseError('not connected');
		}

		@fclose($this->fp);
		$this->fp = null;
		return true;
	}

	/**
	 * Find out if the socket is in blocking mode.
	 *
	 * @access public
	 * @return boolean  The current blocking mode.
	 */
	public function isBlocking()
	{
		return $this->blocking;
	}

	/**
	 * Sets whether the socket connection should be blocking or
	 * not. A read call to a non-blocking socket will return immediately
	 * if there is no data available, whereas it will block until there
	 * is data for blocking sockets.
	 *
	 * @param boolean $mode  True for blocking sockets, false for nonblocking.
	 * @access public
	 * @return mixed true on success or a PEAR_Error instance otherwise
	 */
	public function setBlocking($mode)
	{
		if (!is_resource($this->fp)) {
			return $this->raiseError('not connected');
		}

		$this->blocking = $mode;
		socket_set_blocking($this->fp, $this->blocking);
		return true;
	}

	/**
	 * Sets the timeout value on socket descriptor,
	 * expressed in the sum of seconds and microseconds
	 *
	 * @param integer $seconds  Seconds.
	 * @param integer $microseconds  Microseconds.
	 * @access public
	 * @return mixed true on success or a PEAR_Error instance otherwise
	 */
	public function setTimeout($seconds, $microseconds)
	{
		if (!is_resource($this->fp)) {
			return $this->raiseError('not connected');
		}

		return socket_set_timeout($this->fp, $seconds, $microseconds);
	}

	/**
	 * Sets the file buffering size on the stream.
	 * See php's stream_set_write_buffer for more information.
	 *
	 * @param integer $size	 Write buffer size.
	 * @access public
	 * @return mixed on success or an PEAR_Error object otherwise
	 */
	public function setWriteBuffer($size)
	{
		if (!is_resource($this->fp)) {
			return $this->raiseError('not connected');
		}

		$returned = stream_set_write_buffer($this->fp, $size);
		if ($returned == 0) {
			return true;
		}
		return $this->raiseError('Cannot set write buffer.');
	}

	/**
	 * Returns information about an existing socket resource.
	 * Currently returns four entries in the result array:
	 *
	 * <p>
	 * timed_out (bool) - The socket timed out waiting for data<br>
	 * blocked (bool) - The socket was blocked<br>
	 * eof (bool) - Indicates EOF event<br>
	 * unread_bytes (int) - Number of bytes left in the socket buffer<br>
	 * </p>
	 *
	 * @access public
	 * @return mixed Array containing information about existing socket resource or a PEAR_Error instance otherwise
	 */
	public function getStatus()
	{
		if (!is_resource($this->fp)) {
			return $this->raiseError('not connected');
		}

		return socket_get_status($this->fp);
	}

	/**
	 * Get a specified line of data
	 *
	 * @access public
	 * @return $size bytes of data from the socket, or a PEAR_Error if
	 *		 not connected.
	 */
	public function gets($size)
	{
		if (!is_resource($this->fp)) {
			return $this->raiseError('not connected');
		}

		return @fgets($this->fp, $size);
	}

	/**
	 * Read a specified amount of data. This is guaranteed to return,
	 * and has the added benefit of getting everything in one fread()
	 * chunk; if you know the size of the data you're getting
	 * beforehand, this is definitely the way to go.
	 *
	 * @param integer $size  The number of bytes to read from the socket.
	 * @access public
	 * @return $size bytes of data from the socket, or a PEAR_Error if
	 *   not connected.
	 */
	public function read($size)
	{
		if (!is_resource($this->fp)) {
			return $this->raiseError('not connected');
		}

		return @fread($this->fp, $size);
	}

	/**
	 * Write a specified amount of data.
	 *
	 * @param string  $data	   Data to write.
	 * @param integer $blocksize  Amount of data to write at once.
	 *							NULL means all at once.
	 *
	 * @access public
	 * @return mixed If the socket is not connected, returns an instance of PEAR_Error
	 *   If the write succeeds, returns the number of bytes written
	 *   If the write fails, returns false.
	 */
	public function write($data, $blocksize = null)
	{
		if (!is_resource($this->fp)) {
			return $this->raiseError('not connected');
		}

		if (is_null($blocksize)) {
			return @fwrite($this->fp, $data);
		} else {
			if (is_null($blocksize)) {
				$blocksize = 1024;
			}

			$pos = 0;
			$size = strlen($data);
			while ($pos < $size) {
				$written = @fwrite($this->fp, substr($data, $pos, $blocksize));
				if ($written === false) {
					return false;
				}
				$pos += $written;
			}

			return $pos;
		}
	}

	/**
	 * Write a line of data to the socket, followed by a trailing "\r\n".
	 *
	 * @access public
	 * @return mixed fputs result, or an error
	 */
	public function writeLine($data)
	{
		if (!is_resource($this->fp)) {
			return $this->raiseError('not connected');
		}

		return fwrite($this->fp, $data . "\r\n");
	}

	/**
	 * Tests for end-of-file on a socket descriptor.
	 *
	 * Also returns true if the socket is disconnected.
	 *
	 * @access public
	 * @return bool
	 */
	public function eof()
	{
		return (!is_resource($this->fp) || feof($this->fp));
	}

	/**
	 * Reads a byte of data
	 *
	 * @access public
	 * @return 1 byte of data from the socket, or a PEAR_Error if
	 *   not connected.
	 */
	public function readByte()
	{
		if (!is_resource($this->fp)) {
			return $this->raiseError('not connected');
		}

		return ord(@fread($this->fp, 1));
	}

	/**
	 * Reads a word of data
	 *
	 * @access public
	 * @return 1 word of data from the socket, or a PEAR_Error if
	 *   not connected.
	 */
	public function readWord()
	{
		if (!is_resource($this->fp)) {
			return $this->raiseError('not connected');
		}

		$buf = @fread($this->fp, 2);
		return (ord($buf[0]) + (ord($buf[1]) << 8));
	}

	/**
	 * Reads an int of data
	 *
	 * @access public
	 * @return integer  1 int of data from the socket, or a PEAR_Error if
	 *   not connected.
	 */
	public function readInt()
	{
		if (!is_resource($this->fp)) {
			return $this->raiseError('not connected');
		}

		$buf = @fread($this->fp, 4);
		return (ord($buf[0]) + (ord($buf[1]) << 8) +
				(ord($buf[2]) << 16) + (ord($buf[3]) << 24));
	}

	/**
	 * Reads a zero-terminated string of data
	 *
	 * @access public
	 * @return string, or a PEAR_Error if
	 *		 not connected.
	 */
	public function readString()
	{
		if (!is_resource($this->fp)) {
			return $this->raiseError('not connected');
		}

		$string = '';
		while (($char = @fread($this->fp, 1)) != "\x00")  {
			$string .= $char;
		}
		return $string;
	}

	/**
	 * Reads an IP Address and returns it in a dot formatted string
	 *
	 * @access public
	 * @return Dot formatted string, or a PEAR_Error if
	 *		 not connected.
	 */
	public function readIPAddress()
	{
		if (!is_resource($this->fp)) {
			return $this->raiseError('not connected');
		}

		$buf = @fread($this->fp, 4);
		return sprintf('%d.%d.%d.%d', ord($buf[0]), ord($buf[1]),
					   ord($buf[2]), ord($buf[3]));
	}

	/**
	 * Read until either the end of the socket or a newline, whichever
	 * comes first. Strips the trailing newline from the returned data.
	 *
	 * @access public
	 * @return All available data up to a newline, without that
	 *		 newline, or until the end of the socket, or a PEAR_Error if
	 *		 not connected.
	 */
	public function readLine()
	{
		if (!is_resource($this->fp)) {
			return $this->raiseError('not connected');
		}

		$line = '';
		$timeout = time() + $this->timeout;
		while (!feof($this->fp) && (!$this->timeout || time() < $timeout)) {
			$line .= @fgets($this->fp, $this->lineLength);
			if (substr($line, -1) == "\n") {
				return rtrim($line, "\r\n");
			}
		}
		return $line;
	}

	/**
	 * Read until the socket closes, or until there is no more data in
	 * the inner PHP buffer. If the inner buffer is empty, in blocking
	 * mode we wait for at least 1 byte of data. Therefore, in
	 * blocking mode, if there is no data at all to be read, this
	 * function will never exit (unless the socket is closed on the
	 * remote end).
	 *
	 * @access public
	 *
	 * @return string  All data until the socket closes, or a PEAR_Error if
	 *				 not connected.
	 */
	public function readAll()
	{
		if (!is_resource($this->fp)) {
			return $this->raiseError('not connected');
		}

		$data = '';
		while (!feof($this->fp)) {
			$data .= @fread($this->fp, $this->lineLength);
		}
		return $data;
	}

	/**
	 * Runs the equivalent of the select() system call on the socket
	 * with a timeout specified by tv_sec and tv_usec.
	 *
	 * @param integer $state	Which of read/write/error to check for.
	 * @param integer $tv_sec   Number of seconds for timeout.
	 * @param integer $tv_usec  Number of microseconds for timeout.
	 *
	 * @access public
	 * @return False if select fails, integer describing which of read/write/error
	 *		 are ready, or PEAR_Error if not connected.
	 */
	public function select($state, $tv_sec, $tv_usec = 0)
	{
		if (!is_resource($this->fp)) {
			return $this->raiseError('not connected');
		}

		$read = null;
		$write = null;
		$except = null;
		if ($state & self::READ) {
			$read[] = $this->fp;
		}
		if ($state & self::WRITE) {
			$write[] = $this->fp;
		}
		if ($state & self::ERROR) {
			$except[] = $this->fp;
		}
		if (false === ($sr = stream_select($read, $write, $except, $tv_sec, $tv_usec))) {
			return false;
		}

		$result = 0;
		if (count($read)) {
			$result |= self::READ;
		}
		if (count($write)) {
			$result |= self::WRITE;
		}
		if (count($except)) {
			$result |= self::ERROR;
		}
		return $result;
	}

	/**
	 * Turns encryption on/off on a connected socket.
	 *
	 * @param bool	$enabled  Set this parameter to true to enable encryption
	 *						  and false to disable encryption.
	 * @param integer $type	 Type of encryption. See
	 *   http://se.php.net/manual/en/function.stream-socket-enable-crypto.php for values.
	 *
	 * @access public
	 * @return false on error, true on success and 0 if there isn't enough data and the
	 *		 user should try again (non-blocking sockets only). A PEAR_Error object
	 *		 is returned if the socket is not connected
	 */
	public function enableCrypto($enabled, $type)
	{
		if (version_compare(phpversion(), "5.1.0", ">=")) {
			if (!is_resource($this->fp)) {
				return $this->raiseError('not connected');
			}
			return @stream_socket_enable_crypto($this->fp, $enabled, $type);
		} else {
			return $this->raiseError('SOCKET::enableCrypto() requires php version >= 5.1.0');
		}
	}

}


/**
 * Provides an implementation of the SMTP protocol
 */
class SMTP
{
	/**
	 * The server to connect to.
	 * @var string
	 * @access public
	 */
	public $host = 'localhost';

	/**
	 * The port to connect to.
	 * @var int
	 * @access public
	 */
	public $port = 25;

	/**
	 * The value to give when sending EHLO or HELO.
	 * @var string
	 * @access public
	 */
	public $localhost = 'localhost';

	/**
	 * List of supported authentication methods, in preferential order.
	 * @var array
	 * @access public
	 */
	public $auth_methods = array('DIGEST-MD5', 'CRAM-MD5', 'LOGIN', 'PLAIN');

	/**
	 * Use SMTP command pipelining (specified in RFC 2920) if the SMTP
	 * server supports it.
	 *
	 * When pipeling is enabled, rcptTo(), mailFrom(), sendFrom(),
	 * somlFrom() and samlFrom() do not wait for a response from the
	 * SMTP server but return immediately.
	 *
	 * @var bool
	 * @access public
	 */
	public $pipelining = false;

	/**
	 * Number of pipelined commands.
	 * @var int
	 * @access private
	 */
	private $_pipelined_commands = 0;

	/**
	 * Should debugging output be enabled?
	 * @var boolean
	 * @access private
	 */
	private $_debug = false;

	/**
	 * The socket resource being used to connect to the SMTP server.
	 * @var resource
	 * @access private
	 */
	private $_socket = null;

	/**
	 * The most recent server response code.
	 * @var int
	 * @access private
	 */
	private $_code = -1;

	/**
	 * The most recent server response arguments.
	 * @var array
	 * @access private
	 */
	private $_arguments = array();

	/**
	 * Stores detected features of the SMTP server.
	 * @var array
	 * @access private
	 */
	private $_esmtp = array();

	/**
	 * Instantiates a new Net_SMTP object, overriding any defaults
	 * with parameters that are passed in.
	 *
	 * If you have SSL support in PHP, you can connect to a server
	 * over SSL using an 'ssl://' prefix:
	 *
	 *   // 465 is a common smtps port.
	 *   $smtp = new Net_SMTP('ssl://mail.host.com', 465);
	 *   $smtp->connect();
	 *
	 * @param string  $host	   The server to connect to.
	 * @param integer $port	   The port to connect to.
	 * @param string  $localhost  The value to give when sending EHLO or HELO.
	 * @param boolean $pipeling   Use SMTP command pipelining
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function __construct($host = null, $port = null, $localhost = null, $pipelining = false)
	{
		if (isset($host)) {
			$this->host = $host;
		}
		if (isset($port)) {
			$this->port = $port;
		}
		if (isset($localhost)) {
			$this->localhost = $localhost;
		}
		$this->pipelining = $pipelining;

		$this->_socket = new Socket();

		/* Include the Auth_SASL package.  If the package is not
		 * available, we disable the authentication methods that
		 * depend upon it. */
		if ((@include_once 'Auth/SASL.php') === false) {
			$pos = array_search('DIGEST-MD5', $this->auth_methods);
			unset($this->auth_methods[$pos]);
			$pos = array_search('CRAM-MD5', $this->auth_methods);
			unset($this->auth_methods[$pos]);
		}
	}

	/**
	 * Set the value of the debugging flag.
	 *
	 * @param   boolean $debug	  New value for the debugging flag.
	 *
	 * @access  public
	 * @since   1.1.0
	 */
	public function setDebug($debug)
	{
		$this->_debug = $debug;
	}

	/**
	 * Send the given string of data to the server.
	 *
	 * @param   string  $data	   The string of data to send.
	 *
	 * @return  mixed   True on success or a PEAR_Error object on failure.
	 *
	 * @access  private
	 * @since   1.1.0
	 */
	private function _send($data)
	{
		if ($this->_debug) {
			echo "DEBUG: Send: $data\n";
		}

		if (Error::is_error($error = $this->_socket->write($data))) {
			throw Error::raise('Failed to write to socket: ' . $error->getMessage());
		}

		return true;
	}

	/**
	 * Send a command to the server with an optional string of
	 * arguments.  A carriage return / linefeed (CRLF) sequence will
	 * be appended to each command string before it is sent to the
	 * SMTP server - an error will be thrown if the command string
	 * already contains any newline characters. Use _send() for
	 * commands that must contain newlines.
	 *
	 * @param   string  $command	The SMTP command to send to the server.
	 * @param   string  $args	   A string of optional arguments to append
	 *							  to the command.
	 *
	 * @return  mixed   The result of the _send() call.
	 *
	 * @access  private
	 * @since   1.1.0
	 */
	private function _put($command, $args = '')
	{
		if (!empty($args)) {
			$command .= ' ' . $args;
		}

		if (strcspn($command, "\r\n") !== strlen($command)) {
			throw Error::raise('Commands cannot contain newlines');
		}

		return $this->_send($command . "\r\n");
	}

	/**
	 * Read a reply from the SMTP server.  The reply consists of a response
	 * code and a response message.
	 *
	 * @param   mixed   $valid	  The set of valid response codes.  These
	 *							  may be specified as an array of integer
	 *							  values or as a single integer value.
	 * @param   bool	$later	  Do not parse the response now, but wait
	 *							  until the last command in the pipelined
	 *							  command group
	 *
	 * @return  mixed   True if the server returned a valid response code or
	 *				  a PEAR_Error object is an error condition is reached.
	 *
	 * @access  private
	 * @since   1.1.0
	 *
	 * @see	 getResponse
	 */
	private function _parseResponse($valid, $later = false)
	{
		$this->_code = -1;
		$this->_arguments = array();

		if ($later) {
			$this->_pipelined_commands++;
			return true;
		}

		for ($i = 0; $i <= $this->_pipelined_commands; $i++) {
			while ($line = $this->_socket->readLine()) {
				if ($this->_debug) {
					echo "DEBUG: Recv: $line\n";
				}

				/* If we receive an empty line, the connection has been closed. */
				if (empty($line)) {
					$this->disconnect();
					throw Error::raise('Connection was unexpectedly closed');
				}

				/* Read the code and store the rest in the arguments array. */
				$code = substr($line, 0, 3);
				$this->_arguments[] = trim(substr($line, 4));

				/* Check the syntax of the response code. */
				if (is_numeric($code)) {
					$this->_code = (int)$code;
				} else {
					$this->_code = -1;
					break;
				}

				/* If this is not a multiline response, we're done. */
				if (substr($line, 3, 1) != '-') {
					break;
				}
			}
		}

		$this->_pipelined_commands = 0;

		/* Compare the server's response code with the valid code/codes. */
		if (is_int($valid) && ($this->_code === $valid)) {
			return true;
		} elseif (is_array($valid) && in_array($this->_code, $valid, true)) {
			return true;
		}

		throw Error::raise('Invalid response code received from server', $this->_code);
	}

	/**
	 * Return a 2-tuple containing the last response from the SMTP server.
	 *
	 * @return  array   A two-element array: the first element contains the
	 *				  response code as an integer and the second element
	 *				  contains the response's arguments as a string.
	 *
	 * @access  public
	 * @since   1.1.0
	 */
	public function getResponse()
	{
		return array($this->_code, join("\n", $this->_arguments));
	}

	/**
	 * Attempt to connect to the SMTP server.
	 *
	 * @param   int	 $timeout	The timeout value (in seconds) for the
	 *							  socket connection.
	 * @param   bool	$persistent Should a persistent socket connection
	 *							  be used?
	 *
	 * @return mixed Returns a PEAR_Error with an error message on any
	 *			   kind of failure, or true on success.
	 * @access public
	 * @since  1.0
	 */
	public function connect($timeout = null, $persistent = false)
	{
		$result = $this->_socket->connect($this->host, $this->port,
										  $persistent, $timeout);
		if (Error::is_error($result)) {
			throw Error::raise('Failed to connect socket: ' .
									$result->getMessage());
		}

		if (Error::is_error($error = $this->_parseResponse(220))) {
			return $error;
		}
		if (Error::is_error($error = $this->_negotiate())) {
			return $error;
		}

		return true;
	}

	/**
	 * Attempt to disconnect from the SMTP server.
	 *
	 * @return mixed Returns a PEAR_Error with an error message on any
	 *			   kind of failure, or true on success.
	 * @access public
	 * @since  1.0
	 */
	public function disconnect()
	{
		if (Error::is_error($error = $this->_put('QUIT'))) {
			return $error;
		}
		if (Error::is_error($error = $this->_parseResponse(221))) {
			return $error;
		}
		if (Error::is_error($error = $this->_socket->disconnect())) {
			throw Error::raise('Failed to disconnect socket: ' .
									$error->getMessage());
		}

		return true;
	}

	/**
	 * Attempt to send the EHLO command and obtain a list of ESMTP
	 * extensions available, and failing that just send HELO.
	 *
	 * @return mixed Returns a PEAR_Error with an error message on any
	 *			   kind of failure, or true on success.
	 *
	 * @access private
	 * @since  1.1.0
	 */
	private function _negotiate()
	{
		if (Error::is_error($error = $this->_put('EHLO', $this->localhost))) {
			return $error;
		}

		if (Error::is_error($this->_parseResponse(250))) {
			/* If we receive a 503 response, we're already authenticated. */
			if ($this->_code === 503) {
				return true;
			}

			/* If the EHLO failed, try the simpler HELO command. */
			if (Error::is_error($error = $this->_put('HELO', $this->localhost))) {
				return $error;
			}
			if (Error::is_error($this->_parseResponse(250))) {
				throw Error::raise('HELO was not accepted: ', $this->_code);
			}

			return true;
		}

		foreach ($this->_arguments as $argument) {
			$verb = strtok($argument, ' ');
			$arguments = substr($argument, strlen($verb) + 1,
								strlen($argument) - strlen($verb) - 1);
			$this->_esmtp[$verb] = $arguments;
		}

		if (!isset($this->_esmtp['PIPELINING'])) {
			$this->pipelining = false;
		}

		return true;
	}

	/**
	 * Returns the name of the best authentication method that the server
	 * has advertised.
	 *
	 * @return mixed	Returns a string containing the name of the best
	 *				  supported authentication method or a PEAR_Error object
	 *				  if a failure condition is encountered.
	 * @access private
	 * @since  1.1.0
	 */
	private function _getBestAuthMethod()
	{
		$available_methods = explode(' ', $this->_esmtp['AUTH']);

		foreach ($this->auth_methods as $method) {
			if (in_array($method, $available_methods)) {
				return $method;
			}
		}

		throw Error::raise('No supported authentication methods');
	}

	/**
	 * Attempt to do SMTP authentication.
	 *
	 * @param string The userid to authenticate as.
	 * @param string The password to authenticate with.
	 * @param string The requested authentication method.  If none is
	 *			   specified, the best supported method will be used.
	 *
	 * @return mixed Returns a PEAR_Error with an error message on any
	 *			   kind of failure, or true on success.
	 * @access public
	 * @since  1.0
	 */
	public function auth($uid, $pwd , $method = '')
	{
		if (version_compare(PHP_VERSION, '5.1.0', '>=') && isset($this->_esmtp['STARTTLS'])) {
			if (Error::is_error($result = $this->_put('STARTTLS'))) {
				return $result;
			}
			if (Error::is_error($result = $this->_parseResponse(220))) {
				return $result;
			}
			if (Error::is_error($result = $this->_socket->enableCrypto(true, STREAM_CRYPTO_METHOD_TLS_CLIENT))) {
				return $result;
			} elseif ($result !== true) {
				throw Error::raise('STARTTLS failed');
			}

			/* Send EHLO again to recieve the AUTH string from the
			 * SMTP server. */
			$this->_negotiate();
		}

		if (empty($this->_esmtp['AUTH'])) {
			throw Error::raise('SMTP server does not support authentication');
		}

		/* If no method has been specified, get the name of the best
		 * supported method advertised by the SMTP server. */
		if (empty($method)) {
			if (Error::is_error($method = $this->_getBestAuthMethod())) {
				/* Return the PEAR_Error object from _getBestAuthMethod(). */
				return $method;
			}
		} else {
			$method = strtoupper($method);
			if (!in_array($method, $this->auth_methods)) {
				throw Error::raise("$method is not a supported authentication method");
			}
		}

		switch ($method) {
		case 'DIGEST-MD5':
			$result = $this->_authDigest_MD5($uid, $pwd);
			break;

		case 'CRAM-MD5':
			$result = $this->_authCRAM_MD5($uid, $pwd);
			break;

		case 'LOGIN':
			$result = $this->_authLogin($uid, $pwd);
			break;

		case 'PLAIN':
			$result = $this->_authPlain($uid, $pwd);
			break;

		default:
			$result = Error::raise("$method is not a supported authentication method");
			break;
		}

		/* If an error was encountered, return the PEAR_Error object. */
		if (Error::is_error($result)) {
			return $result;
		}

		return true;
	}

	/**
	 * Authenticates the user using the DIGEST-MD5 method.
	 *
	 * @param string The userid to authenticate as.
	 * @param string The password to authenticate with.
	 *
	 * @return mixed Returns a PEAR_Error with an error message on any
	 *			   kind of failure, or true on success.
	 * @access private
	 * @since  1.1.0
	 */
	private function _authDigest_MD5($uid, $pwd)
	{
		if (Error::is_error($error = $this->_put('AUTH', 'DIGEST-MD5'))) {
			return $error;
		}
		/* 334: Continue authentication request */
		if (Error::is_error($error = $this->_parseResponse(334))) {
			/* 503: Error: already authenticated */
			if ($this->_code === 503) {
				return true;
			}
			return $error;
		}

		$challenge = base64_decode($this->_arguments[0]);
		$digest = &Auth_SASL::factory('digestmd5');
		$auth_str = base64_encode($digest->getResponse($uid, $pwd, $challenge,
													   $this->host, "smtp"));

		if (Error::is_error($error = $this->_put($auth_str))) {
			return $error;
		}
		/* 334: Continue authentication request */
		if (Error::is_error($error = $this->_parseResponse(334))) {
			return $error;
		}

		/* We don't use the protocol's third step because SMTP doesn't
		 * allow subsequent authentication, so we just silently ignore
		 * it. */
		if (Error::is_error($error = $this->_put(''))) {
			return $error;
		}
		/* 235: Authentication successful */
		if (Error::is_error($error = $this->_parseResponse(235))) {
			return $error;
		}
	}

	/**
	 * Authenticates the user using the CRAM-MD5 method.
	 *
	 * @param string The userid to authenticate as.
	 * @param string The password to authenticate with.
	 *
	 * @return mixed Returns a PEAR_Error with an error message on any
	 *			   kind of failure, or true on success.
	 * @access private
	 * @since  1.1.0
	 */
	private function _authCRAM_MD5($uid, $pwd)
	{
		if (Error::is_error($error = $this->_put('AUTH', 'CRAM-MD5'))) {
			return $error;
		}
		/* 334: Continue authentication request */
		if (Error::is_error($error = $this->_parseResponse(334))) {
			/* 503: Error: already authenticated */
			if ($this->_code === 503) {
				return true;
			}
			return $error;
		}

		$challenge = base64_decode($this->_arguments[0]);
		$cram = &Auth_SASL::factory('crammd5');
		$auth_str = base64_encode($cram->getResponse($uid, $pwd, $challenge));

		if (Error::is_error($error = $this->_put($auth_str))) {
			return $error;
		}

		/* 235: Authentication successful */
		if (Error::is_error($error = $this->_parseResponse(235))) {
			return $error;
		}
	}

	/**
	 * Authenticates the user using the LOGIN method.
	 *
	 * @param string The userid to authenticate as.
	 * @param string The password to authenticate with.
	 *
	 * @return mixed Returns a PEAR_Error with an error message on any
	 *			   kind of failure, or true on success.
	 * @access private
	 * @since  1.1.0
	 */
	private function _authLogin($uid, $pwd)
	{
		if (Error::is_error($error = $this->_put('AUTH', 'LOGIN'))) {
			return $error;
		}
		/* 334: Continue authentication request */
		if (Error::is_error($error = $this->_parseResponse(334))) {
			/* 503: Error: already authenticated */
			if ($this->_code === 503) {
				return true;
			}
			return $error;
		}

		if (Error::is_error($error = $this->_put(base64_encode($uid)))) {
			return $error;
		}
		/* 334: Continue authentication request */
		if (Error::is_error($error = $this->_parseResponse(334))) {
			return $error;
		}

		if (Error::is_error($error = $this->_put(base64_encode($pwd)))) {
			return $error;
		}

		/* 235: Authentication successful */
		if (Error::is_error($error = $this->_parseResponse(235))) {
			return $error;
		}

		return true;
	}

	/**
	 * Authenticates the user using the PLAIN method.
	 *
	 * @param string The userid to authenticate as.
	 * @param string The password to authenticate with.
	 *
	 * @return mixed Returns a PEAR_Error with an error message on any
	 *			   kind of failure, or true on success.
	 * @access private
	 * @since  1.1.0
	 */
	private function _authPlain($uid, $pwd)
	{
		if (Error::is_error($error = $this->_put('AUTH', 'PLAIN'))) {
			return $error;
		}
		/* 334: Continue authentication request */
		if (Error::is_error($error = $this->_parseResponse(334))) {
			/* 503: Error: already authenticated */
			if ($this->_code === 503) {
				return true;
			}
			return $error;
		}

		$auth_str = base64_encode(chr(0) . $uid . chr(0) . $pwd);

		if (Error::is_error($error = $this->_put($auth_str))) {
			return $error;
		}

		/* 235: Authentication successful */
		if (Error::is_error($error = $this->_parseResponse(235))) {
			return $error;
		}

		return true;
	}

	/**
	 * Send the HELO command.
	 *
	 * @param string The domain name to say we are.
	 *
	 * @return mixed Returns a PEAR_Error with an error message on any
	 *			   kind of failure, or true on success.
	 * @access public
	 * @since  1.0
	 */
	public function helo($domain)
	{
		if (Error::is_error($error = $this->_put('HELO', $domain))) {
			return $error;
		}
		if (Error::is_error($error = $this->_parseResponse(250))) {
			return $error;
		}

		return true;
	}

	/**
	 * Return the list of SMTP service extensions advertised by the server.
	 *
	 * @return array The list of SMTP service extensions.
	 * @access public
	 * @since 1.3
	 */
	public function getServiceExtensions()
	{
		return $this->_esmtp;
	}

	/**
	 * Send the MAIL FROM: command.
	 *
	 * @param string $sender	The sender (reverse path) to set.
	 * @param string $params	String containing additional MAIL parameters,
	 *						  such as the NOTIFY flags defined by RFC 1891
	 *						  or the VERP protocol.
	 *
	 *						  If $params is an array, only the 'verp' option
	 *						  is supported.  If 'verp' is true, the XVERP
	 *						  parameter is appended to the MAIL command.  If
	 *						  the 'verp' value is a string, the full
	 *						  XVERP=value parameter is appended.
	 *
	 * @return mixed Returns a PEAR_Error with an error message on any
	 *			   kind of failure, or true on success.
	 * @access public
	 * @since  1.0
	 */
	public function mailFrom($sender, $params = null)
	{
		$args = "FROM:<$sender>";

		/* Support the deprecated array form of $params. */
		if (is_array($params) && isset($params['verp'])) {
			/* XVERP */
			if ($params['verp'] === true) {
				$args .= ' XVERP';

			/* XVERP=something */
			} elseif (trim($params['verp'])) {
				$args .= ' XVERP=' . $params['verp'];
			}
		} elseif (is_string($params)) {
			$args .= ' ' . $params;
		}

		if (Error::is_error($error = $this->_put('MAIL', $args))) {
			return $error;
		}
		if (Error::is_error($error = $this->_parseResponse(250, $this->pipelining))) {
			return $error;
		}

		return true;
	}

	/**
	 * Send the RCPT TO: command.
	 *
	 * @param string $recipient The recipient (forward path) to add.
	 * @param string $params	String containing additional RCPT parameters,
	 *						  such as the NOTIFY flags defined by RFC 1891.
	 *
	 * @return mixed Returns a PEAR_Error with an error message on any
	 *			   kind of failure, or true on success.
	 *
	 * @access public
	 * @since  1.0
	 */
	public function rcptTo($recipient, $params = null)
	{
		$args = "TO:<$recipient>";
		if (is_string($params)) {
			$args .= ' ' . $params;
		}

		if (Error::is_error($error = $this->_put('RCPT', $args))) {
			return $error;
		}
		if (Error::is_error($error = $this->_parseResponse(array(250, 251), $this->pipelining))) {
			return $error;
		}

		return true;
	}

	/**
	 * Quote the data so that it meets SMTP standards.
	 *
	 * This is provided as a separate public function to facilitate
	 * easier overloading for the cases where it is desirable to
	 * customize the quoting behavior.
	 *
	 * @param string $data  The message text to quote. The string must be passed
	 *					  by reference, and the text will be modified in place.
	 *
	 * @access public
	 * @since  1.2
	 */
	public function quotedata(&$data)
	{
		/* Change Unix (\n) and Mac (\r) linefeeds into
		 * Internet-standard CRLF (\r\n) linefeeds. */
		$data = preg_replace(array('/(?<!\r)\n/','/\r(?!\n)/'), "\r\n", $data);

		/* Because a single leading period (.) signifies an end to the
		 * data, legitimate leading periods need to be "doubled"
		 * (e.g. '..'). */
		$data = str_replace("\n.", "\n..", $data);
	}

	/**
	 * Send the DATA command.
	 *
	 * @param string $data  The message body to send.
	 *
	 * @return mixed Returns a PEAR_Error with an error message on any
	 *			   kind of failure, or true on success.
	 * @access public
	 * @since  1.0
	 */
	public function data($data)
	{
		/* RFC 1870, section 3, subsection 3 states "a value of zero
		 * indicates that no fixed maximum message size is in force".
		 * Furthermore, it says that if "the parameter is omitted no
		 * information is conveyed about the server's fixed maximum
		 * message size". */
		if (isset($this->_esmtp['SIZE']) && ($this->_esmtp['SIZE'] > 0)) {
			if (strlen($data) >= $this->_esmtp['SIZE']) {
				$this->disconnect();
				throw Error::raise('Message size excedes the server limit');
			}
		}

		/* Quote the data based on the SMTP standards. */
		$this->quotedata($data);

		if (Error::is_error($error = $this->_put('DATA'))) {
			return $error;
		}
		if (Error::is_error($error = $this->_parseResponse(354))) {
			return $error;
		}

		if (Error::is_error($result = $this->_send($data . "\r\n.\r\n"))) {
			return $result;
		}
		if (Error::is_error($error = $this->_parseResponse(250, $this->pipelining))) {
			return $error;
		}

		return true;
	}

	/**
	 * Send the SEND FROM: command.
	 *
	 * @param string The reverse path to send.
	 *
	 * @return mixed Returns a PEAR_Error with an error message on any
	 *			   kind of failure, or true on success.
	 * @access public
	 * @since  1.2.6
	 */
	public function sendFrom($path)
	{
		if (Error::is_error($error = $this->_put('SEND', "FROM:<$path>"))) {
			return $error;
		}
		if (Error::is_error($error = $this->_parseResponse(250, $this->pipelining))) {
			return $error;
		}

		return true;
	}

	/**
	 * Backwards-compatibility wrapper for sendFrom().
	 *
	 * @param string The reverse path to send.
	 *
	 * @return mixed Returns a PEAR_Error with an error message on any
	 *			   kind of failure, or true on success.
	 *
	 * @access	  public
	 * @since	   1.0
	 * @deprecated  1.2.6
	 */
	public function send_from($path)
	{
		return sendFrom($path);
	}

	/**
	 * Send the SOML FROM: command.
	 *
	 * @param string The reverse path to send.
	 *
	 * @return mixed Returns a PEAR_Error with an error message on any
	 *			   kind of failure, or true on success.
	 * @access public
	 * @since  1.2.6
	 */
	public function somlFrom($path)
	{
		if (Error::is_error($error = $this->_put('SOML', "FROM:<$path>"))) {
			return $error;
		}
		if (Error::is_error($error = $this->_parseResponse(250, $this->pipelining))) {
			return $error;
		}

		return true;
	}

	/**
	 * Backwards-compatibility wrapper for somlFrom().
	 *
	 * @param string The reverse path to send.
	 *
	 * @return mixed Returns a PEAR_Error with an error message on any
	 *			   kind of failure, or true on success.
	 *
	 * @access	  public
	 * @since	   1.0
	 * @deprecated  1.2.6
	 */
	public function soml_from($path)
	{
		return somlFrom($path);
	}

	/**
	 * Send the SAML FROM: command.
	 *
	 * @param string The reverse path to send.
	 *
	 * @return mixed Returns a PEAR_Error with an error message on any
	 *			   kind of failure, or true on success.
	 * @access public
	 * @since  1.2.6
	 */
	public function samlFrom($path)
	{
		if (Error::is_error($error = $this->_put('SAML', "FROM:<$path>"))) {
			return $error;
		}
		if (Error::is_error($error = $this->_parseResponse(250, $this->pipelining))) {
			return $error;
		}

		return true;
	}

	/**
	 * Backwards-compatibility wrapper for samlFrom().
	 *
	 * @param string The reverse path to send.
	 *
	 * @return mixed Returns a PEAR_Error with an error message on any
	 *			   kind of failure, or true on success.
	 *
	 * @access	  public
	 * @since	   1.0
	 * @deprecated  1.2.6
	 */
	public function saml_from($path)
	{
		return samlFrom($path);
	}

	/**
	 * Send the RSET command.
	 *
	 * @return mixed Returns a PEAR_Error with an error message on any
	 *			   kind of failure, or true on success.
	 * @access public
	 * @since  1.0
	 */
	public function rset()
	{
		if (Error::is_error($error = $this->_put('RSET'))) {
			return $error;
		}
		if (Error::is_error($error = $this->_parseResponse(250, $this->pipelining))) {
			return $error;
		}

		return true;
	}

	/**
	 * Send the VRFY command.
	 *
	 * @param string The string to verify
	 *
	 * @return mixed Returns a PEAR_Error with an error message on any
	 *			   kind of failure, or true on success.
	 * @access public
	 * @since  1.0
	 */
	public function vrfy($string)
	{
		/* Note: 251 is also a valid response code */
		if (Error::is_error($error = $this->_put('VRFY', $string))) {
			return $error;
		}
		if (Error::is_error($error = $this->_parseResponse(array(250, 252)))) {
			return $error;
		}

		return true;
	}

	/**
	 * Send the NOOP command.
	 *
	 * @return mixed Returns a PEAR_Error with an error message on any
	 *			   kind of failure, or true on success.
	 * @access public
	 * @since  1.0
	 */
	public function noop()
	{
		if (Error::is_error($error = $this->_put('NOOP'))) {
			return $error;
		}
		if (Error::is_error($error = $this->_parseResponse(250))) {
			return $error;
		}

		return true;
	}

	/**
	 * Backwards-compatibility method.  identifySender()'s functionality is
	 * now handled internally.
	 *
	 * @return  boolean	 This method always return true.
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function identifySender()
	{
		return true;
	}

}


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
		if ( $headerElements instanceof Error ) {
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

		$this->_smtp = new SMTP($this->host, $this->port, $this->localhost);

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
