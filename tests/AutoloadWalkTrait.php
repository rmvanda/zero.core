<?php

namespace Zero\Tests\Core;

/**
 * The walk shared by the autoload map tests: every .php file below a folder,
 * and the classes a file declares in a Zero\ namespace.
 */
trait AutoloadWalkTrait
{
    /**
     * @param string[] $skip directory names not to descend into
     * @return \Generator<string>
     */
    private function phpFiles(string $dir, array $skip): \Generator
    {
        if (!is_dir($dir)) {
            return;
        }
        $names = scandir($dir) ?: [];
        sort($names);
        foreach ($names as $name) {
            if ($name[0] === '.') {
                continue;
            }
            $path = $dir . $name;
            if (is_dir($path)) {
                if (!in_array($name, $skip, true)) {
                    yield from $this->phpFiles($path . '/', $skip);
                }
            } elseif (str_ends_with($name, '.php')) {
                yield $path;
            }
        }
    }

    /**
     * Fully qualified names of the classes, interfaces, traits and enums a
     * file declares in a namespace that begins Zero\.
     *
     * @return string[]
     */
    private function declaredClasses(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $namespace = '';
        $found = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    $t = $tokens[$j];
                    if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                        $namespace .= $t[1];
                    } elseif ($t === ';' || $t === '{') {
                        break;
                    }
                }
                $i = $j;
            } elseif (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                // "Foo::class" and "new class" are not declarations
                $prev = $this->previousCode($tokens, $i);
                if ($prev !== null && is_array($prev) && in_array($prev[0], [T_DOUBLE_COLON, T_NEW], true)) {
                    continue;
                }
                for ($j = $i + 1; $j < $count; $j++) {
                    $t = $tokens[$j];
                    if (is_array($t) && $t[0] === T_STRING) {
                        if (str_starts_with($namespace, 'Zero\\')) {
                            $found[] = $namespace . '\\' . $t[1];
                        }
                        break;
                    }
                    if (is_array($t) && $t[0] === T_WHITESPACE) {
                        continue;
                    }
                    break;
                }
            }
        }

        return $found;
    }

    private function previousCode(array $tokens, int $i): array|string|null
    {
        for ($k = $i - 1; $k >= 0; $k--) {
            if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $tokens[$k];
        }

        return null;
    }
}
