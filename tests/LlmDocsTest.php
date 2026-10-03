<?php

declare(strict_types=1);

/**
 * The guides for agents (docs/llm/*, 0031 E.7) are followed literally, so
 * what they tell an agent to run must exist: every command and every option
 * they name is one the command line knows.
 */

return [
    'every request-shield command and option the agents\' guides name is one the command line has' => function (): void {
        if (!function_exists('exec')) {
            skip('no exec');
        }
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rsCli()) . ' 2>&1', $usage);
        $usage = implode("\n", $usage);
        truthy(strpos($usage, 'usage: request-shield') === 0, $usage);
        $docs = ['install.md', 'write-rules.md', 'check.md', 'prompts/install.md'];
        $seen = 0;
        foreach ($docs as $doc) {
            $text = (string) file_get_contents(dirname(__DIR__) . "/docs/llm/$doc");
            truthy($text !== '', "docs/llm/$doc is there");
            // A call, as the guides write it: php <path>request-shield.php <command> …
            preg_match_all('/php \S*request-shield\.php ([a-z][a-z-]+)([^\n`]*)/', $text, $m, PREG_SET_ORDER);
            foreach ($m as [$all, $command, $rest]) {
                $seen++;
                truthy(preg_match('/request-shield ' . preg_quote($command, '/') . '\b/', $usage) === 1, "docs/llm/$doc: \"$command\" is a command ($all)");
                preg_match_all('/--([a-z-]+)/', $rest, $opts);
                foreach ($opts[1] as $opt) {
                    truthy(strpos($usage, "--$opt") !== false, "docs/llm/$doc: --$opt is an option ($all)");
                }
            }
        }
        truthy($seen >= 10, "the guides name the commands they use: $seen");
    },
];
