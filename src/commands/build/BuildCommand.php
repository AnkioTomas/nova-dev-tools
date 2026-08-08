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

        $sourceTinyphp = $this->resolveTemplateDir('win' . DIRECTORY_SEPARATOR . 'tinyphp');
        if ($sourceTinyphp === null) {
            Output::error('Windows resource not found: win/tinyphp');
            return;
        }

        $winRoot = $this->distDir . DIRECTORY_SEPARATOR . 'win-temp';
        $this->removePath($winRoot);
        $tinyphpDir = $winRoot . DIRECTORY_SEPARATOR . 'tinyphp';
        if (!$this->copyDir($sourceTinyphp, $tinyphpDir)) {
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
