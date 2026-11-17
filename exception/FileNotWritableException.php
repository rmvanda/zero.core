<?php

namespace Zero\Core\Exception;

/**
 * A file cannot be written: its folder or lock file is not usable, or the file
 * is deliberately read-only. The message names the path. ModulesIni::save()
 * throws it.
 */
class FileNotWritableException extends \RuntimeException
{
}
