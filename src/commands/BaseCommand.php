<?php

namespace nova\commands;

use FilesystemIterator;
use nova\console\Output;
use Phar;

/**
 * 基础命令类
 * 为所有命令提供公共功能，包括用户交互、文件操作和命令执行
 */
abstract class BaseCommand
{
    /**
     * 初始化命令
     * 每个具体命令必须实现此方法
     */
    abstract public function init();

    /** @var string 命令执行的工作目录 */
    public string $workingDir;

    /** @var array 命令选项 */
    protected array $options;

    /**
     * 构造函数
     *
     * @param string $workingDir 工作目录
     * @param array $options 命令选项
     */
    public function __construct(string $workingDir, array $options)
    {
        $this->workingDir = $workingDir;
        $this->options = $options;
    }

    /**
     * 从当前命令选项中提取布尔标记。
     *
     * 命中任一标记返回 true，并将其从 $this->options 中移除。
     */
    protected function takeFlag(string ...$flags): bool
    {
        if ($flags === []) {
            return false;
        }

        $found = false;
        $nextOptions = [];

        foreach ($this->options as $option) {
            if (in_array($option, $flags, true)) {
                $found = true;
                continue;
            }
            $nextOptions[] = $option;
        }

        $this->options = $nextOptions;

        return $found;
    }

    /**
     * 提示用户输入，支持默认值
     * 将用户交互委托给 Output::prompt 以保证一致的输出处理
     *
     * @param string $promptMessage 提示信息
     * @param string $default 用户未输入时的默认值
     * @return string 用户输入或默认值
     */
    protected function prompt(string $promptMessage, string $default = ""): string
    {
        return Output::prompt($promptMessage, $default);
    }


    /**
     * 递归删除目录或文件
     *
     * @param string $path 要删除的路径
     * @return bool 成功返回 true，失败返回 false
     */
    function removePath(string $path): bool
    {
        if (!file_exists($path)) {
            return true;
        }

        if (is_file($path)) {
            return @unlink($path);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $fileinfo) {
            $fileinfo->isDir() ? @rmdir($fileinfo->getPathname()) : @unlink($fileinfo->getPathname());
        }

        return @rmdir($path);
    }

    /**
     * 递归复制目录及其所有内容
     *
     * @param string $sourceDir 源目录路径
     * @param string $targetDir 目标目录路径
     * @return bool 成功返回 true，失败返回 false
     */
    protected function copyDir(string $sourceDir, string $targetDir): bool
    {
        if (!is_dir($sourceDir)) {
            Output::error("Directory does not exist: $sourceDir");
            return false;
        }

        @mkdir($targetDir, 0777, true);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $path => $file) {
            $relPath = substr($path, strlen($sourceDir) + 1);
            $target = $targetDir . DIRECTORY_SEPARATOR . $relPath;
            $file->isDir() ? @mkdir($target, 0777, true) : @copy($path, $target);
        }

        return true;
    }

    /**
     * 解析模板目录路径（PHAR / 源码双模式）
     *
     * phar 内缺失时回落到同级磁盘（例如源码树里的 win/tinyphp）。
     */
    protected function resolveTemplateDir(string $relative): ?string
    {
        $relative = $this->normalizeRelative($relative);
        $path = $this->toolPath($relative);
        if (is_dir($path)) {
            return $path;
        }

        foreach ($this->externalResourceBases($relative) as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * 解析工具内嵌资源文件路径（PHAR / 源码双模式）
     */
    protected function resolveResource(string $relative): ?string
    {
        $relative = $this->normalizeRelative($relative);
        $path = $this->toolPath($relative);
        if (is_file($path)) {
            return $path;
        }

        foreach ($this->externalResourceBases($relative) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function toolPath(string $relative): string
    {
        $relative = $this->normalizeRelative($relative);
        $runningPhar = Phar::running(false);
        $basePath = $runningPhar !== ''
            ? 'phar://' . $runningPhar
            : dirname(__DIR__);

        return $basePath . DIRECTORY_SEPARATOR . $relative;
    }

    /**
     * phar 旁的磁盘候选路径：仓库布局 src/<rel>，或紧挨 phar 的 <rel>
     *
     * @return list<string>
     */
    private function externalResourceBases(string $relative): array
    {
        $runningPhar = Phar::running(false);
        if ($runningPhar === '') {
            return [];
        }

        $root = dirname($runningPhar);
        return [
            $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $relative,
            $root . DIRECTORY_SEPARATOR . $relative,
        ];
    }

    private function normalizeRelative(string $relative): string
    {
        return trim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relative), DIRECTORY_SEPARATOR);
    }

    /**
     * 执行系统命令，并在命令结束后统一输出 stdout / stderr。
     *
     * @param string      $command     要执行的命令
     * @param string|null $dir         工作目录，null 表示使用当前目录
     * @param bool        $ignoreError 为 true 时忽略非零退出码与 stderr 输出（跨平台替代 "|| true" / "2>/dev/null"）
     * @return bool|string 成功（或 $ignoreError=true）返回 stdout，失败返回 false
     */
    function exec(string $command, string $dir = null, bool $ignoreError = false): bool|string
    {
        if ($ignoreError) {
            Output::commandLine('~', $command, true);
        } else {
            Output::commandLine('$', $command);
        }

        if ($dir !== null && !is_dir($dir)) {
            Output::error("Working directory does not exist: $dir");
            return $ignoreError ? '' : false;
        }

        $descriptorspec = [
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($command, $descriptorspec, $pipes, $dir);

        if (!is_resource($process)) {
            Output::error("Failed to start process");
            return $ignoreError ? '' : false;
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $stdout = $stdout === false ? '' : $stdout;
        $stderr = $stderr === false ? '' : $stderr;

        foreach (preg_split('/\r\n|\n|\r/', rtrim($stdout)) as $line) {
            if ($line !== '') {
                Output::commandStdout($line);
            }
        }

        if (!$ignoreError) {
            foreach (preg_split('/\r\n|\n|\r/', rtrim($stderr)) as $line) {
                if ($line !== '') {
                    Output::commandStderr($line);
                }
            }
        }

        if ($exitCode !== 0) {
            if (!$ignoreError) {
                Output::error("Command failed with exit code: $exitCode");
            }
            return $ignoreError ? $stdout : false;
        }

        return $stdout;
    }

    /**
     * 以最大努力模式执行命令：忽略非零退出码，不显示 stderr。
     *
     * 跨平台替代方案，无需任何 Shell 特有语法：
     *   - Unix:    cmd || true          →  $this->execSafe('cmd')
     *   - Unix:    cmd 2>/dev/null      →  $this->execSafe('cmd')
     *   - Windows: cmd 2>nul            →  $this->execSafe('cmd')
     *
     * @param string      $command 命令（不含任何 Shell 错误抑制符号）
     * @param string|null $dir     工作目录
     * @return string stdout 内容（失败时返回空字符串）
     */
    protected function execSafe(string $command, string $dir = null): string
    {
        $result = $this->exec($command, $dir, true);
        return is_string($result) ? $result : '';
    }

    /**
     * 以流式方式执行命令，实时输出 stdout / stderr，适合长期运行的进程（如服务器）。
     *
     * @param string      $command 要执行的命令
     * @param string|null $dir     工作目录
     * @return int 进程退出码
     */
    protected function execStream(string $command, string $dir = null): int
    {
        Output::commandLine('$', $command);

        if ($dir !== null && !is_dir($dir)) {
            Output::error("Working directory does not exist: $dir");
            return 1;
        }

        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $dir);

        if (!is_resource($process)) {
            Output::error("Failed to start process");
            return 1;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $open = [1 => $pipes[1], 2 => $pipes[2]];

        while (!empty($open)) {
            $read = array_values($open);
            $write = $except = null;

            if (stream_select($read, $write, $except, 1) === false) {
                break;
            }

            foreach ($read as $pipe) {
                while (($line = fgets($pipe)) !== false) {
                    $line = rtrim($line, "\r\n");
                    if ($line !== '') {
                        $pipe === $pipes[1]
                            ? Output::commandStdout($line)
                            : Output::commandStderr($line);
                    }
                }
                if (feof($pipe)) {
                    $open = array_filter($open, fn($p) => $p !== $pipe);
                }
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process);
    }

}