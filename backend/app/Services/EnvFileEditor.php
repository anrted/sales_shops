<?php

namespace App\Services;

use RuntimeException;

class EnvFileEditor
{
    public function __construct(
        private readonly ?string $path = null,
    ) {}

    /**
     * @param  array<string, scalar|null>  $values
     */
    public function update(array $values): void
    {
        $path = $this->path ?? base_path('.env');
        $contents = is_file($path) ? (string) file_get_contents($path) : '';

        if ($contents === '' && is_file($path) && !is_readable($path)) {
            throw new RuntimeException("Unable to read env file at {$path}.");
        }

        $lines = preg_split("/\r\n|\n|\r/", $contents) ?: [];
        $pending = $values;

        foreach ($lines as $index => $line) {
            if (!preg_match('/^\s*([A-Z0-9_]+)\s*=.*$/', $line, $matches)) {
                continue;
            }

            $key = $matches[1];
            if (!array_key_exists($key, $pending)) {
                continue;
            }

            $lines[$index] = $key.'='.$this->formatValue($pending[$key]);
            unset($pending[$key]);
        }

        foreach ($pending as $key => $value) {
            $lines[] = $key.'='.$this->formatValue($value);
        }

        $normalized = implode(PHP_EOL, $lines);
        if ($normalized !== '' && !str_ends_with($normalized, PHP_EOL)) {
            $normalized .= PHP_EOL;
        }

        if (file_put_contents($path, $normalized) === false) {
            throw new RuntimeException("Unable to write env file at {$path}.");
        }
    }

    private function formatValue(string|int|float|bool|null $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $string = (string) $value;
        if ($string === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z0-9._\-]+$/', $string)) {
            return $string;
        }

        $escaped = str_replace(
            ["\\", '"', "\r", "\n"],
            ["\\\\", '\"', '\r', '\n'],
            $string,
        );

        return '"'.$escaped.'"';
    }
}
