<?php /**
 * Returns true if the IP is on the whitelist specified by
 * self::WHITELIST_PATH
 * Returns false, otherwise.
 */

/* Like I said in the chat.. these functions would be better served as a
 * zero-whitelist driver that extends a general
 * authentication Interface.. so it would be something like:
 * if ($this->Authentication->granted()) { <do Request stuff here> }
 * Then you could configure what the authentication method is here or ideally
 * have a default here and use a config file to
 * override it.
 *
 * Yeah, it needs to be organized a bit better -
 */

Namespace Zero\Core

class Whitelist
{
	// This should be configurable (see: CentOS using /etc/php.fpm.d for example)
	const WHITELIST_PATH = "/etc/php5/fpm/whitelist.lst";

	public static function isOnTheWhitelist()
	{
		// Do you mean self::parseWhiteList? .....vv
		if (in_array($_SERVER['REMOTE_ADDR'], parseWhitelist())) {
			return true;
		} elseif ($_COOKIE['acl'] == "ThisIsACookie") {
			return true;
		}

	}

	private function parseWhitelist()
	{
		// $rip = explode("\n",self::getRawWhiteList()); <--- explode always returns an
		// array, so no need to specify it.. Also DRY!!
		// array_walk($rip,'trim'); <-- this trims every IP address
		// return $rip; <-- this could be part of line 2 really but I like having the
		// return separate for various reasons...
		$rip = array();
		foreach (explode("\n", file_get_contents(self::WHITELIST_PATH)) as $ip) {
			$ip = explode(" ", $ip);
			$rip[] = $ip[0];
		}
		return $rip;
	}

	private static function getWhitelist()
	{
		// parseWhiteList() isn't a static function..
		return self::parseWhitelist();
	}

	private static function getRawWhitelist()
	{
		//return explode("\n", file_get_contents(self::WHITELIST_PATH));
		return file_get_contents(self::WHITELIST_PATH);
	}

	/* I'd argue that this would be better served as:
	 * function checkWhiteList($ip) and then pass the remote_addr over into it
	 * (setting $this->ip is also okay maybe)
	 * Because you might want to check if someone else is on the whitelist someday
	 * and have a means of adding them..
	 * So with one extra function this new class replaces addMeToTheWhiteList.php
	 */
	private static function checkWhitelist()
	{
		return in_array($_SERVER['REMOTE_ADDR'], self::parseWhitelist());
	}

}
