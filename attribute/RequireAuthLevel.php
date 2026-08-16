<?php

namespace Zero\Core\Attribute;
use \Attribute;
use \Zero\Core\Console;
use \Zero\Core\HTTPError;

#[Attribute]
class RequireAuthLevel{

    public $approved; // never actually get used, but is there if needed. 

    public function __construct(public $level = 9){}

    public function handler(){
        // session_status() checked FIRST, before touching User::current(): with
        // no session there is nothing to look up, and current()->authLevel() is
        // a database-backed call (memoized, but still a query) — no sense
        // paying for it just to be denied a line later.
        if (session_status() == PHP_SESSION_NONE) {
            Console::warn("RequireAuthLevel attribute blocked request: required level {$this->level}, user level: none");
            throw new HTTPError(401, "Authentication required");
        }

        // Reads through User::current()->authLevel() (database, memoized per
        // instance) rather than a session cache, so a revoked/raised auth
        // level takes effect on the very next request.
        $userLevel = \Zero\Core\User::current()?->authLevel() ?? 0;

        if( !$userLevel
            ||  $userLevel < $this->level
        ){
            Console::warn("RequireAuthLevel attribute blocked request: required level {$this->level}, user level: " . ($userLevel ?: 'none'));
            throw new HTTPError(401, "Authentication required");
        }
        return $this->approved = true;
    }

}
