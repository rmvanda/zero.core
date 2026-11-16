<?php

namespace Zero\Core;

/**
 * ModulesIni: the one writer of app/modules.ini.
 *
 * Modules reads the file; this class changes it. The file holds only the
 * departures from the tier defaults, in two sections:
 *
 *   [enabled]
 *   Sitemap = off
 *
 *   [order]
 *   Blog = 10
 *
 * The constructor reads the file (a missing file is an empty one) and takes
 * a hash of its content. It accepts only what this class writes: the two
 * sections, keys that are identifiers, a boolean under [enabled] and a whole
 * number from 0 to 9999 under [order], no key twice (also not in two
 * spellings of one module name). Anything else throws a \RuntimeException that
 * names the file and the key, and the file is left as it is. Names already in
 * the file that no module has (a module since removed) are kept.
 *
 * save() takes an exclusive lock on a sibling modules.ini.lock, reads the file
 * again, refuses (StaleFileException) when its content is no longer the one
 * the constructor read, writes the whole file to a temp file in the same
 * folder, checks that Modules reads that temp file back as exactly the
 * intended entries, and renames it over the original, so a reader sees the old
 * file or the new one and never half of either. Names are checked against
 * Modules (every module of every root, whatever spelling) and the real
 * directory name is what gets written; a name that is an INI keyword (On, Off,
 * Yes, No, True, False, None, Null) cannot be a key and is refused.
 *
 * The FOLDER must be writable by the user the web server runs as (the write is
 * temp file then rename). An existing file keeps its permission bits and is
 * replaced even when its owner is someone else, unless it has no write bit at
 * all (0444): that is deliberately read-only and save() refuses it. A new file
 * is 0664. The class reads no global constant (tests hand it a temp path) and
 * never changes an owner.
 */
class ModulesIni
{
    private const HEADER = '; Only departures from the defaults. Written by the Admin modules screen.';
    private const ORDER_MIN = 0;
    private const ORDER_MAX = 9999;
    private const NEW_FILE_MODE = 0664;
    /** Words the INI scanner reads as values, so they cannot be keys */
    private const KEYWORDS = ['on', 'off', 'yes', 'no', 'true', 'false', 'none', 'null'];

    /** @var array<string,bool> name => value, as read from [enabled] */
    private array $enabled = [];
    /** @var array<string,int> name => value, as read from [order] */
    private array $order = [];
    private string $hash;

    /**
     * @throws \RuntimeException when the file exists and cannot be read, or its content is not what this class writes
     */
    public function __construct(private string $file)
    {
        $content = $this->read();
        $this->hash = self::contentHash($content);
        $this->parse($content);
    }

    /**
     * The hash of the file's content as the constructor (or the last save)
     * left it. A form that carries it back proves the file is unchanged since
     * the page was drawn.
     */
    public function hash(): string
    {
        return $this->hash;
    }

    /**
     * Whether a name can be written to the file: an identifier that is not an
     * INI keyword. Any module Modules lists has such a name unless it is a keyword.
     */
    public static function isSettable(string $name): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) === 1
            && !in_array(strtolower($name), self::KEYWORDS, true);
    }

    /**
     * Turn a module on or off; null removes the entry (back to the default).
     * An entry equal to the module's default is not kept.
     *
     * @throws \DomainException for a required module, a name no module has or a name that cannot be an INI key
     */
    public function setEnabled(string $name, ?bool $enabled): void
    {
        $real = $this->realName($name);
        if (Modules::tier($real) === 'required') {
            throw new \DomainException("{$real} is required and cannot be turned off");
        }

        $this->enabled = self::without($this->enabled, $real);
        if ($enabled !== null && $enabled !== (Modules::tier($real) !== 'example')) {
            $this->enabled[$real] = $enabled;
        }
    }

    /**
     * Set the load order of a module; null removes it.
     *
     * @throws \DomainException for a name no module has, a name that cannot be an INI key or an order outside 0..9999
     */
    public function setOrder(string $name, ?int $order): void
    {
        $real = $this->realName($name);
        if ($order !== null && ($order < self::ORDER_MIN || $order > self::ORDER_MAX)) {
            throw new \DomainException('Order must be a whole number from ' . self::ORDER_MIN . ' to ' . self::ORDER_MAX);
        }

        $this->order = self::without($this->order, $real);
        if ($order !== null) {
            $this->order[$real] = $order;
        }
    }

    /**
     * Write the file. Throws StaleFileException ('app/modules.ini changed
     * since it was read') when the file is no longer what the constructor
     * read, FileNotWritableException naming the path when the folder, the lock
     * file or the file cannot be written, and \RuntimeException when the text
     * to write would not read back as the intended entries. On any failure the
     * old file stays and no temp file is left.
     */
    public function save(): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new FileNotWritableException("Cannot write {$this->file}: the folder {$dir} is not writable");
        }

        $lockFile = $this->file . '.lock';
        if (is_link($lockFile)) {
            throw new FileNotWritableException("Cannot write {$this->file}: the lock file {$lockFile} is a symlink");
        }
        // A lock file another user created may not be writable by this one;
        // flock() works on a read handle too.
        $lock = @fopen($lockFile, 'c') ?: (file_exists($lockFile) ? @fopen($lockFile, 'r') : false);
        if ($lock === false) {
            throw new FileNotWritableException("Cannot write {$this->file}: the lock file {$lockFile} cannot be opened");
        }

        $temp = null;
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new FileNotWritableException("Cannot write {$this->file}: the lock file {$lockFile} cannot be locked");
            }
            if (self::contentHash($this->read()) !== $this->hash) {
                throw new StaleFileException('app/modules.ini changed since it was read');
            }

            $mode = self::NEW_FILE_MODE;
            if (is_file($this->file)) {
                $mode = fileperms($this->file) & 0777;
                if (($mode & 0222) === 0) {
                    throw new FileNotWritableException("Cannot write {$this->file}: it is read-only");
                }
            }

            $text = $this->render();
            $temp = $dir . '/.' . basename($this->file) . '.' . bin2hex(random_bytes(6)) . '.tmp';
            $handle = @fopen($temp, 'x');
            if ($handle === false) {
                $temp = null;
                throw new FileNotWritableException("Cannot write {$this->file}: a temp file in {$dir} cannot be created");
            }
            $written = fwrite($handle, $text);
            $synced = fflush($handle) && fsync($handle);
            fclose($handle);
            if ($written !== strlen($text) || !$synced) {
                throw new FileNotWritableException("Cannot write {$this->file}: the temp file {$temp} could not be written");
            }
            if (!@chmod($temp, $mode)) {
                throw new FileNotWritableException("Cannot write {$this->file}: the permissions of the temp file {$temp} could not be set");
            }
            $this->assertReadsBack($temp);
            if (!$this->replace($temp, $this->file)) {
                throw new FileNotWritableException("Cannot write {$this->file}: the temp file {$temp} could not replace it");
            }
            $temp = null;
            $this->hash = self::contentHash($text);
        } finally {
            if ($temp !== null) {
                @unlink($temp);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Put the finished temp file in the original's place. A step of its own so
     * a test can make it fail.
     */
    protected function replace(string $temp, string $target): bool
    {
        return @rename($temp, $target);
    }

    /**
     * Parse the temp file the way Modules parses the real one and refuse
     * unless it holds exactly the intended entries.
     *
     * @throws \RuntimeException
     */
    private function assertReadsBack(string $temp): void
    {
        $parsed = Modules::parseIniFile($temp);
        if ($parsed === null
            || ($parsed['enabled'] ?? null) !== self::sorted($this->enabled)
            || ($parsed['order'] ?? null) !== self::sorted($this->order)
            || count($parsed) !== 2) {
            throw new \RuntimeException("Refusing to write {$this->file}: the text would not read back as the intended entries");
        }
    }

    /**
     * The file's content, or null when there is no file.
     */
    private function read(): ?string
    {
        if (!file_exists($this->file)) {
            return null;
        }
        $content = is_file($this->file) ? @file_get_contents($this->file) : false;
        if ($content === false) {
            throw new \RuntimeException("Cannot read {$this->file}");
        }

        return $content;
    }

    private static function contentHash(?string $content): string
    {
        return $content === null ? 'absent' : sha1($content);
    }

    /**
     * @throws \RuntimeException naming the file and the key when the content is not what this class writes
     */
    private function parse(?string $content): void
    {
        if ($content === null || trim($content) === '') {
            return;
        }

        $parsed = @parse_ini_string($content, true, INI_SCANNER_TYPED);
        if ($parsed === false) {
            throw new \RuntimeException("{$this->file} is not valid INI and was left as it is");
        }

        $seen = [];
        foreach ($parsed as $section => $entries) {
            if (!in_array($section, ['enabled', 'order'], true) || !is_array($entries)) {
                throw new \RuntimeException("{$this->file} has '{$section}' where only the sections [enabled] and [order] are expected; it was left as it is");
            }
            foreach ($entries as $name => $value) {
                $name = (string) $name;
                $where = "{$this->file}: [{$section}] key '{$name}'";
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
                    throw new \RuntimeException("{$where} is not a name; the file was left as it is");
                }
                if ($section === 'enabled' ? !is_bool($value) : (!is_int($value) || $value < self::ORDER_MIN || $value > self::ORDER_MAX)) {
                    throw new \RuntimeException("{$where} has a value that is not "
                        . ($section === 'enabled' ? 'on or off' : 'a whole number from 0 to 9999') . '; the file was left as it is');
                }
                if (isset($seen[$section][self::fold($name)])) {
                    throw new \RuntimeException("{$where} appears twice (compared ignoring case and hyphens); the file was left as it is");
                }
                $seen[$section][self::fold($name)] = true;

                if ($section === 'enabled') {
                    $this->enabled[$name] = $value;
                } else {
                    $this->order[$name] = $value;
                }
            }
        }

        // parse_ini_string keeps only the last of a repeated key; find repeats in the text.
        $section = null;
        $lines = [];
        foreach (preg_split('/\R/', $content) as $line) {
            $line = trim($line);
            if (preg_match('/^\[([^\]]*)\]/', $line, $m)) {
                $section = $m[1];
            } elseif (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $m)) {
                $key = $section . '/' . self::fold($m[1]);
                if (isset($lines[$key])) {
                    throw new \RuntimeException("{$this->file}: [{$section}] key '{$m[1]}' appears twice; the file was left as it is");
                }
                $lines[$key] = true;
            }
        }
    }

    /**
     * @throws \DomainException when no module has the name, or it cannot be written as an INI key
     */
    private function realName(string $name): string
    {
        $real = Modules::name($name);
        if ($real === null) {
            throw new \DomainException('Unknown module');
        }
        if (!self::isSettable($real)) {
            throw new \DomainException("{$real} cannot be set from app/modules.ini");
        }

        return $real;
    }

    /**
     * The entries without the one for $real, whatever its spelling in the file.
     */
    private static function without(array $entries, string $real): array
    {
        $wanted = self::fold($real);
        foreach (array_keys($entries) as $key) {
            if (self::fold((string) $key) === $wanted) {
                unset($entries[$key]);
            }
        }

        return $entries;
    }

    private static function fold(string $name): string
    {
        return strtolower(str_replace('-', '', $name));
    }

    private function render(): string
    {
        $text = self::HEADER . "\n[enabled]\n";
        foreach (self::sorted($this->enabled) as $name => $value) {
            $text .= "{$name} = " . ($value ? 'on' : 'off') . "\n";
        }
        $text .= "\n[order]\n";
        foreach (self::sorted($this->order) as $name => $value) {
            $text .= "{$name} = {$value}\n";
        }

        return $text;
    }

    private static function sorted(array $entries): array
    {
        uksort($entries, fn($a, $b) => strcasecmp((string) $a, (string) $b) ?: strcmp((string) $a, (string) $b));

        return $entries;
    }
}
