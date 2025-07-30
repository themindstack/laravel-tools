<?php

namespace Quangphuc\LaravelTools\Helpers;

class Dir
{
    public static function scan($root): array
    {
        $result = [];
        foreach(scandir($root) as $filename) {
            if ($filename === '.' || $filename === '..') {
                continue;
            }
            $filePath = $root . '/' . $filename;
            if (is_dir($filePath)) {
                foreach (self::scan($filePath) as $childFilename) {
                    $result[] = $filename . '/' . $childFilename;
                }
            } else {
                $result[] = $filename;
            }
        }
        return $result;
    }
}
