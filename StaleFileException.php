<?php

namespace Zero\Core;

/**
 * A file changed on disk after the caller read it, so the caller's change is
 * not written. ModulesIni::save() throws it.
 */
class StaleFileException extends \RuntimeException
{
}
