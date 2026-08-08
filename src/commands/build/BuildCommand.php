<?php

namespace nova\commands\build;

use nova\commands\BaseCommand;
use nova\commands\docker\DockerCommand;
use nova\console\Output;

class BuildCommand extends BaseCommand
{
    private $nova;
    private $distDir;

    public function __construct($workingDir, $options)
    {
        parent::__construct($workingDir, $options);
        $this->nova = json_decode(file_get_contents($workingDir . DIRECTORY_SEPARATOR . "package.json"), true);
        $name = $this->nova['name'];
        if (($pos = strrpos($name, '/')) !== false) {
            $name = substr($name, $pos + 1);
        }
        $this->nova['name'] = $name;
        $this->distDir = $this->workingDir . DIRECTORY_SEPARATOR . "dist";
    }

    public function init()
    {
        $type = $this->options[0] ?? 'source';
        $valid = ['source', 'windows', 'docker', 'all'];
        if (!in_array($type, $valid, true)) {
            Output::error("Unknown build type: $type (expected: source|windows|docker|all)");
            return;
        }

        Output::section("Build Project");

        $version = $this->prompt("Version", $this->nova['version']);
        Output::info("Building version: $version");

        $preparedSrc = $this->prepareSrc($version);

        $types = $type === 'all' ? ['source', 'windows', 'docker'] : [$type];
        foreach ($types as $t) {
            match ($t) {
                'source' => $this->packSource($version, $preparedSrc),
                'windows' => $this->packWindows($version, $preparedSrc),
                'docker' => $this->packDocker($version, $preparedSrc),
            };
        }

        $this->removePath($preparedSrc);

        Output::writeln();
        Output::success('Build complete.');
        Output::writeln();
    }

    private function prepareSrc(string $version): string
    {
        $temp = $this->distDir . DIRECTORY_SEPARATOR . 'temp';
        $this->removePath($this->distDir);
        mkdir($temp, 0777, true);

        $this->copyDir($this->workingDir . DIRECTORY_SEPARATOR . 'src', $temp);

        $config = include $temp . DIRECTORY_SEPARATOR . 'example.config.php';
        $config['version'] = $version;
        $config['debug'] = false;
        file_put_contents(
            $temp . DIRECTORY_SEPARATOR . 'example.config.php',
            "<?php\nreturn " . var_export($config, true) . ";\n"
        );

        $configFile = $temp . DIRECTORY_SEPARATOR . 'config.php';
        if (file_exists($configFile)) {
            unlink($configFile);
        }

        $this->removePath($temp . DIRECTORY_SEPARATOR . 'runtime');
        mkdir($temp . DIRECTORY_SEPARATOR . 'runtime', 0777, true);

        return $temp;
    }

    private function packSource(string $version, string $preparedSrc): void
    {
        Output::step('Packing source archive…');
        $zipPath = $this->distDir . DIRECTORY_SEPARATOR . $this->nova['name'] . '-' . $version . '.zip';
        $this->createZip($zipPath, $preparedSrc);
        Output::step("Created → dist/{$this->nova['name']}-{$version}.zip");
    }

    private function packWindows(string $version, string $preparedSrc): void
    {
        Output::step('Packing Windows archive…');

        $winRoot = $this->distDir . DIRECTORY_SEPARATOR . 'win-temp';
        $this->removePath($winRoot);
        mkdir($winRoot, 0777, true);

        // phar 内嵌的是 zip；源码开发时可直接用目录
        if (!$this->materializeTinyphp($winRoot)) {
            return;
        }

        $tinyphpDir = $winRoot . DIRECTORY_SEPARATOR . 'tinyphp';
        if (!is_dir($tinyphpDir)) {
            Output::error('Windows resource invalid: expected tinyphp/ after extract/copy');
            $this->removePath($winRoot);
            return;
        }

        // Local runtime junk — start.bat regenerates these.
        foreach (['port.txt', 'listen.conf', 'public.conf'] as $junk) {
            $path = $tinyphpDir . DIRECTORY_SEPARATOR . $junk;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->removePath($tinyphpDir . DIRECTORY_SEPARATOR . 'logs');
        mkdir($tinyphpDir . DIRECTORY_SEPARATOR . 'logs', 0777, true);

        $wwwDir = $tinyphpDir . DIRECTORY_SEPARATOR . 'www';
        $this->removePath($wwwDir);
        mkdir($wwwDir, 0777, true);
        $this->copyDir($preparedSrc, $wwwDir);

        $zipPath = $this->distDir . DIRECTORY_SEPARATOR . $this->nova['name'] . '-' . $version . '-windows.zip';
        $this->createZip($zipPath, $tinyphpDir, 'tinyphp');
        $this->removePath($winRoot);

        Output::step("Created → dist/{$this->nova['name']}-{$version}-windows.zip");
    }

    /**
     * 优先解压 win/tinyphp.zip（phar 内嵌），否则复制 win/tinyphp 目录。
     */
    private function materializeTinyphp(string $winRoot): bool
    {
        $zipResource = $this->resolveResource('win' . DIRECTORY_SEPARATOR . 'tinyphp.zip');
        if ($zipResource !== null) {
            $tinyphpZip = $zipResource;
            $tempZip = null;
            if (str_starts_with($zipResource, 'phar://')) {
                $tempZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nova-tinyphp-' . uniqid('', true) . '.zip';
                if (!copy($zipResource, $tempZip)) {
                    Output::error('Failed to copy tinyphp.zip from phar.');
                    return false;
                }
                $tinyphpZip = $tempZip;
            }

            $archive = new \ZipArchive();
            if ($archive->open($tinyphpZip) !== true) {
                Output::error('Failed to open tinyphp.zip');
                if ($tempZip !== null) {
                    @unlink($tempZip);
                }
                return false;
            }
            $archive->extractTo($winRoot);
            $archive->close();
            if ($tempZip !== null) {
                @unlink($tempZip);
            }
            return true;
        }

        $sourceTinyphp = $this->resolveTemplateDir('win' . DIRECTORY_SEPARATOR . 'tinyphp');
        if ($sourceTinyphp === null) {
            Output::error('Windows resource not found: win/tinyphp.zip or win/tinyphp');
            return false;
        }

        return $this->copyDir($sourceTinyphp, $winRoot . DIRECTORY_SEPARATOR . 'tinyphp');
    }

    private function packDocker(string $version, string $preparedSrc): void
    {
        Output::step('Packing Docker archive…');

        $dockerRoot = $this->distDir . DIRECTORY_SEPARATOR . 'docker-temp';
        $this->removePath($dockerRoot);
        mkdir($dockerRoot, 0777, true);

        file_put_contents($dockerRoot . DIRECTORY_SEPARATOR . 'Dockerfile', DockerCommand::dockerfileContent());
        file_put_contents($dockerRoot . DIRECTORY_SEPARATOR . 'docker-compose.yml', DockerCommand::composeContent());
        $this->copyDir($preparedSrc, $dockerRoot . DIRECTORY_SEPARATOR . 'src');

        $zipPath = $this->distDir . DIRECTORY_SEPARATOR . $this->nova['name'] . '-' . $version . '-docker.zip';
        $this->createZip($zipPath, $dockerRoot);
        $this->removePath($dockerRoot);

        Output::step("Created → dist/{$this->nova['name']}-{$version}-docker.zip");
    }

    private function createZip(string $zipPath, string $sourceDir, string $zipPrefix = ''): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            Output::error("Failed to create zip: $zipPath");
            return;
        }
        $prefix = $zipPrefix === '' ? '' : rtrim(str_replace('\\', '/', $zipPrefix), '/') . '/';
        $this->addFileToZip($sourceDir, $zip, $sourceDir, $prefix);
        $zip->close();
    }

    private function addFileToZip(string $dir, \ZipArchive $zip, string $baseDir, string $zipPrefix = ''): void
    {
        $relativeDir = substr($dir, strlen($baseDir) + 1);
        if ($relativeDir !== false && $relativeDir !== '') {
            $zip->addEmptyDir($zipPrefix . str_replace('\\', '/', $relativeDir));
        }

        $handle = opendir($dir);
        while (($file = readdir($handle)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $fullPath = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($fullPath)) {
                $this->addFileToZip($fullPath, $zip, $baseDir, $zipPrefix);
            } else {
                $relative = substr($fullPath, strlen($baseDir) + 1);
                $relative = str_replace('\\', '/', $relative);
                $zip->addFile($fullPath, $zipPrefix . $relative);
            }
        }
        closedir($handle);
    }
}
