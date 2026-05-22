<?php
spl_autoload_register(function (string $class): void {
    $prefix = '';
    $baseDir = __DIR__ . DIRECTORY_SEPARATOR;

    $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});
