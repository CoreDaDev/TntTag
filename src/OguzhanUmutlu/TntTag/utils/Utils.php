<?php

namespace OguzhanUmutlu\TntTag\utils;

class Utils {
    private static $unique = 0;
    public static function removeDirectory(string $dir): void {
        if(basename($dir) == "." || basename($dir) == ".." || !is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if($item != "." || $item != "..") {
                if(is_dir($dir . "/" . $item))
                    self::removeDirectory($dir . "/" . $item);
                if(is_file($dir . "/" . $item))
                    unlink($dir . "/" . $item);
            }
        }
        rmdir($dir);
    }
    public static function getUniqueNumber(): int {
        self::$unique++;
        return self::$unique;
    }
}