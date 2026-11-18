<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Support;

use ArtisanToolbox\Dapter\Contracts\DataSource;
use ArtisanToolbox\Dapter\Http\DapterException;
use PhpToken;
use ReflectionClass;

/** @internal */
final class SourceVersion
{
    public function forSource(DataSource $source): string
    {
        $declarations = [];
        $this->collect(new ReflectionClass($source), $declarations);
        ksort($declarations, SORT_STRING);
        $hash = hash_init('sha256');
        foreach ($declarations as $name => $bytes) {
            hash_update($hash, strlen($name).':'.$name.strlen($bytes).':'.$bytes);
        }

        return hash_final($hash);
    }

    /**
     * @template T of object
     *
     * @param  ReflectionClass<T>  $class
     * @param  array<string,string>  $declarations
     */
    private function collect(ReflectionClass $class, array &$declarations): void
    {
        $name = $class->isAnonymous() ? 'anonymous' : $class->getName();

        if (array_key_exists($name, $declarations)) {
            return;
        }
        $declarations[$name] = $this->declaration($class);
        $parent = $class->getParentClass();

        if ($parent !== false) {
            $this->collect($parent, $declarations);
        }
        foreach ($class->getTraits() as $trait) {
            $this->collect($trait, $declarations);
        }
    }

    /**
     * @template T of object
     *
     * @param  ReflectionClass<T>  $class
     */
    private function declaration(ReflectionClass $class): string
    {
        $file = $class->getFileName();
        $bytes = $file === false ? false : file_get_contents($file);

        if ($bytes === false) {
            throw DapterException::configuration();
        }
        $tokens = PhpToken::tokenize($bytes);
        $namespace = '';
        foreach ($tokens as $index => $token) {
            if ($token->id === T_NAMESPACE) {
                $namespace = '';
                for ($next = $index + 1; isset($tokens[$next]) && ! in_array($tokens[$next]->text, [';', '{'], true); $next++) {
                    if ($tokens[$next]->id !== T_WHITESPACE) {
                        $namespace .= $tokens[$next]->text;
                    }
                }
            }

            if (! in_array($token->id, [T_CLASS, T_TRAIT], true)) {
                continue;
            }
            $next = $index + 1;
            while (isset($tokens[$next]) && in_array($tokens[$next]->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $next++;
            }
            $name = isset($tokens[$next]) && $tokens[$next]->id === T_STRING ? ltrim($namespace.'\\'.$tokens[$next]->text, '\\') : null;

            if ($class->isAnonymous() ? $name !== null || $token->line !== $class->getStartLine() : $name !== $class->getName()) {
                continue;
            }
            $start = $this->start($tokens, $index);
            $depth = 0;
            $opened = false;
            for ($end = $index; isset($tokens[$end]); $end++) {
                $current = $tokens[$end];

                if (in_array($current->id, [ord('{'), T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;
                    $opened = true;
                } elseif ($current->id === ord('}')) {
                    $depth--;

                    if ($opened && $depth === 0) {
                        return substr($bytes, $tokens[$start]->pos, $current->pos + 1 - $tokens[$start]->pos);
                    }
                }
            }
        }

        throw DapterException::configuration();
    }

    /** @param array<PhpToken> $tokens */
    private function start(array $tokens, int $index): int
    {
        $start = $index;
        for ($before = $index - 1; $before >= 0; $before--) {
            $token = $tokens[$before];

            if ($token->id === T_WHITESPACE) {
                continue;
            }

            if (in_array($token->id, [T_ABSTRACT, T_FINAL, T_READONLY, T_DOC_COMMENT, T_COMMENT], true)) {
                $start = $before;

                continue;
            }

            if ($token->text !== ']') {
                break;
            }
            $depth = 1;
            while (--$before >= 0) {
                $token = $tokens[$before];

                if ($token->text === ']') {
                    $depth++;
                } elseif ($token->text === '[' || $token->id === T_ATTRIBUTE) {
                    $depth--;

                    if ($depth === 0) {
                        $start = $before;

                        break;
                    }
                }
            }
        }

        return $start;
    }
}
