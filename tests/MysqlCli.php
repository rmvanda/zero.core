<?php

namespace Zero\Tests\Core;

/**
 * Runs the mysql client the way the database tests do: the account comes from
 * the client's own option files (the shell user's ~/.my.cnf), so no credential
 * passes through PHP, a command line or an output.
 */
final class MysqlCli
{
    /** Runs the mysql client; returns [exit code, stdout, stderr]. */
    public static function run(string $sql, ?string $database = null, ?string $file = null, bool $skipNames = false): array
    {
        $cmd = ['mysql', '--batch', '--raw'];
        if ($skipNames) {
            $cmd[] = '--skip-column-names';
        }
        if ($database !== null) {
            $cmd[] = $database;
        }
        $cmd[] = '-e';
        $cmd[] = $file !== null ? 'source ' . $file : $sql;
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return [1, '', 'mysql client not runnable'];
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out, $err];
    }
}
