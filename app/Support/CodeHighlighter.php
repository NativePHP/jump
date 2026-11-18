<?php

namespace App\Support;

/**
 * Tiny PHP syntax highlighter for the Playground's code blocks.
 *
 * Splits a snippet into lines of `[text, kind]` tokens; the view renders each
 * token as an inline <text> run coloured from {@see self::classes()}. It only
 * knows the PHP these samples use (facades, method chains, closures, named
 * arguments, strings, numbers, variables), which is all it needs to.
 */
class CodeHighlighter
{
    private const KEYWORDS = [
        'function', 'fn', 'return', 'new', 'true', 'false', 'null', 'use', 'static',
        'if', 'else', 'foreach', 'as', 'echo',
    ];

    /**
     * Text colour per token kind, light and dark (GitHub palettes).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const PALETTE = [
        'text' => ['#24292F', '#E6EDF3'],
        'keyword' => ['#CF222E', '#FF7B72'],
        'class' => ['#953800', '#FFA657'],
        'method' => ['#8250DF', '#D2A8FF'],
        'variable' => ['#0550AE', '#79C0FF'],
        'string' => ['#0A3069', '#A5D6FF'],
        'number' => ['#0550AE', '#79C0FF'],
        'param' => ['#116329', '#7EE787'],
        'operator' => ['#CF222E', '#FF7B72'],
        'punct' => ['#57606A', '#8B949E'],
        'comment' => ['#6E7781', '#8B949E'],
    ];

    /** Tailwind-style text classes for a token kind (light + `dark:`). */
    public static function classes(string $kind): string
    {
        [$light, $dark] = self::PALETTE[$kind] ?? self::PALETTE['text'];

        return "text-[{$light}] dark:text-[{$dark}]";
    }

    /**
     * @return list<list<array{0: string, 1: string}>> lines of [text, kind] tokens
     */
    public static function lines(string $code): array
    {
        return array_map(self::tokenize(...), explode("\n", rtrim($code)));
    }

    /** @return list<array{0: string, 1: string}> */
    private static function tokenize(string $line): array
    {
        $tokens = [];
        $length = strlen($line);
        $i = 0;
        $previous = '';

        $push = function (string $text, string $kind) use (&$tokens, &$previous): void {
            $tokens[] = [$text, $kind];
            if (trim($text) !== '') {
                $previous = $text;
            }
        };

        while ($i < $length) {
            $rest = substr($line, $i);
            $char = $line[$i];

            if (preg_match('/^\s+/', $rest, $m)) {
                $tokens[] = [$m[0], 'text'];
                $i += strlen($m[0]);
            } elseif (str_starts_with($rest, '//')) {
                $push($rest, 'comment');
                break;
            } elseif ($char === "'" || $char === '"') {
                preg_match('/^(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/', $rest, $m);
                $string = $m[0] ?? $rest;
                $push($string, 'string');
                $i += strlen($string);
            } elseif (preg_match('/^\$[A-Za-z_]\w*/', $rest, $m)) {
                $push($m[0], 'variable');
                $i += strlen($m[0]);
            } elseif (preg_match('/^\d+(?:\.\d+)?/', $rest, $m)) {
                $push($m[0], 'number');
                $i += strlen($m[0]);
            } elseif (preg_match('/^(->|=>|::|\?->)/', $rest, $m)) {
                $push($m[0], 'operator');
                $i += strlen($m[0]);
            } elseif (preg_match('/^[A-Za-z_]\w*/', $rest, $m)) {
                $word = $m[0];
                $after = substr($rest, strlen($word));
                $kind = match (true) {
                    in_array($previous, ['->', '?->', '::'], true) && str_starts_with($after, '(') => 'method',
                    in_array($previous, ['->', '?->'], true) => 'variable',
                    in_array(strtolower($word), self::KEYWORDS, true) => 'keyword',
                    str_starts_with($after, '::') || (ctype_upper($word[0]) && ! str_starts_with($after, '(')) => 'class',
                    preg_match('/^:(?!:)/', $after) === 1 => 'param',
                    str_starts_with($after, '(') => 'method',
                    default => 'text',
                };
                $push($word, $kind);
                $i += strlen($word);
            } else {
                $push($char, in_array($char, ['(', ')', '{', '}', '[', ']', ',', ';', ':'], true) ? 'punct' : 'text');
                $i++;
            }
        }

        return $tokens;
    }
}
