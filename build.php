<?php

// 创建Phar对象
$pharFile = 'nova.phar';

if (file_exists($pharFile)) {
    unlink($pharFile);
}

$srcDir = realpath(dirname(__FILE__) . '/src');
if ($srcDir === false) {
    fwrite(STDERR, "src/ not found\n");
    exit(1);
}

$tinyphpDir = $srcDir . DIRECTORY_SEPARATOR . 'win' . DIRECTORY_SEPARATOR . 'tinyphp';
if (!is_dir($tinyphpDir)) {
    fwrite(STDERR, "src/win/tinyphp not found — cannot embed Windows runtime\n");
    exit(1);
}

// 目录 → zip，phar 里只内嵌 zip，不塞整棵目录树
$tempZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nova-tinyphp-' . uniqid('', true) . '.zip';
zipTinyphpDir($tinyphpDir, $tempZip);
echo 'Embedded win/tinyphp.zip (' . round(filesize($tempZip) / 1024 / 1024, 2) . " MB)\n";

$phar = new Phar($pharFile, 0, $pharFile);

$excludeDir = 'win' . DIRECTORY_SEPARATOR . 'tinyphp';
$excludeZip = 'win' . DIRECTORY_SEPARATOR . 'tinyphp.zip';

$directory = new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS);
$filter = new RecursiveCallbackFilterIterator(
    $directory,
    static function ($current) use ($srcDir, $excludeDir, $excludeZip) {
        $rel = substr($current->getPathname(), strlen($srcDir) + 1);
        if ($rel === $excludeDir || str_starts_with($rel, $excludeDir . DIRECTORY_SEPARATOR)) {
            return false;
        }
        if ($rel === $excludeZip) {
            return false;
        }
        return true;
    }
);

$phar->buildFromIterator(new RecursiveIteratorIterator($filter), $srcDir);
$phar->compressFiles(Phar::GZ);

// zip 本身已压缩：在 compressFiles 之后加入，避免二次 gzip
$phar->addFile($tempZip, 'win/tinyphp.zip');
@unlink($tempZip);

$phar->setDefaultStub('start.php', 'start.php');

$size = filesize($pharFile);
echo "Phar包 {$pharFile} 已成功创建（" . round($size / 1024 / 1024, 2) . " MB）。\n";

/**
 * 打包 tinyphp 目录为 zip（顶层前缀 tinyphp/），去掉本地运行时垃圾。
 */
function zipTinyphpDir(string $dir, string $zipPath): void
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fwrite(STDERR, "Failed to create $zipPath\n");
        exit(1);
    }

    $skipFiles = ['port.txt' => true, 'listen.conf' => true, 'public.conf' => true, '.DS_Store' => true];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $path => $fileInfo) {
        $rel = substr($path, strlen($dir) + 1);
        $relUnix = str_replace('\\', '/', $rel);

        if ($relUnix === 'logs' || str_starts_with($relUnix, 'logs/')) {
            continue;
        }
        if ($relUnix === 'www' || str_starts_with($relUnix, 'www/')) {
            continue;
        }
        if (isset($skipFiles[$fileInfo->getFilename()])) {
            continue;
        }

        $entry = 'tinyphp/' . $relUnix;
        if ($fileInfo->isDir()) {
            $zip->addEmptyDir($entry);
        } else {
            $zip->addFile($path, $entry);
        }
    }

    $zip->addEmptyDir('tinyphp/logs');
    $zip->addEmptyDir('tinyphp/www');
    $zip->close();
}
