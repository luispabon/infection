<?php
/**
 * Copyright © 2017-2018 Maks Rafalko
 *
 * License: https://opensource.org/licenses/BSD-3-Clause New BSD License
 */

declare(strict_types=1);

namespace Infection\TestFramework;

use Infection\Finder\AbstractExecutableFinder;
use Infection\Finder\Exception\FinderException;
use Infection\Mutant\MutantInterface;
use Infection\Process\ExecutableFinder\PhpExecutableFinder;
use Infection\TestFramework\Config\InitialConfigBuilder;
use Infection\TestFramework\Config\MutationConfigBuilder;
use Infection\Utils\VersionParser;
use Symfony\Component\Process\Process;

/**
 * @internal
 */
abstract class AbstractTestFrameworkAdapter
{
    /**
     * @var AbstractExecutableFinder
     */
    private $testFrameworkFinder;

    /**
     * @var CommandLineArgumentsAndOptionsBuilder
     */
    private $argumentsAndOptionsBuilder;

    /**
     * @var InitialConfigBuilder
     */
    private $initialConfigBuilder;

    /**
     * @var MutationConfigBuilder
     */
    private $mutationConfigBuilder;

    /**
     * @var VersionParser
     */
    private $versionParser;

    /**
     * @var string
     */
    private $cachedPhpPath;

    /**
     * @var bool
     */
    private $cachedIncludedArgs;

    public function __construct(
        AbstractExecutableFinder $testFrameworkFinder,
        InitialConfigBuilder $initialConfigBuilder,
        MutationConfigBuilder $mutationConfigBuilder,
        CommandLineArgumentsAndOptionsBuilder $argumentsAndOptionsBuilder,
        VersionParser $versionParser
    ) {
        $this->testFrameworkFinder = $testFrameworkFinder;
        $this->initialConfigBuilder = $initialConfigBuilder;
        $this->mutationConfigBuilder = $mutationConfigBuilder;
        $this->argumentsAndOptionsBuilder = $argumentsAndOptionsBuilder;
        $this->versionParser = $versionParser;
    }

    abstract public function testsPass(string $output): bool;

    abstract public function getName(): string;

    /**
     * Returns path to the test framework's executable
     *
     * Examples:
     *     bin/phpspec [arguments] [--options]
     *     bin/phpunit
     *     vendor/phpunit/phpunit/phpunit
     *     /usr/bin/php bin/phpunit
     *     bin/phpunit.bat
     *
     * @param string $configPath
     * @param string $extraOptions
     * @param bool $includePhpArgs
     * @param array $phpExtraOptions
     *
     * @return string
     */
    public function getExecutableCommandLine(
        string $configPath,
        string $extraOptions,
        bool $includePhpArgs = true,
        array $phpExtraOptions = []
    ): string {
        return sprintf(
            '%s %s',
            $this->makeExecutable(
                $this->testFrameworkFinder->find(),
                $includePhpArgs,
                $phpExtraOptions
            ),
            $this->argumentsAndOptionsBuilder->build($configPath, $extraOptions)
        );
    }

    /**
     * Prefix commands with exec outside Windows to ensure process timeouts are enforced and end PHP processes properly.
     *
     * @param string $frameworkPath
     * @param bool $includeArgs
     * @param array $phpExtraArgs
     *
     * @return string
     */
    private function makeExecutable(string $frameworkPath, bool $includeArgs = true, array $phpExtraArgs = []): string
    {
        $frameworkPath = realpath($frameworkPath);

        if ('\\' == \DIRECTORY_SEPARATOR) {
            if (false !== strpos($frameworkPath, '.bat')) {
                return $frameworkPath;
            }

            return sprintf(
                '%s %s %s',
                $this->findPhp($includeArgs),
                implode(' ', $phpExtraArgs),
                $frameworkPath
            );
        }

        /*
         * Run an executable as it is if we're using a standard CLI and
         * there's a standard interpreter available on PATH.
         *
         * This lets folks use, say, a bash wrapper over phpunit.
         */
        if ('cli' == \PHP_SAPI && empty($phpExtraArgs) && is_executable($frameworkPath) && `command -v php`) {
            return sprintf(
                '%s %s',
                'exec',
                $frameworkPath
            );
        }

        /*
         * In all other cases run it with a chosen PHP interpreter
         */
        return sprintf(
            '%s %s %s %s',
            'exec',
            $this->findPhp($includeArgs),
            implode(' ', $phpExtraArgs),
            $frameworkPath
        );
    }

    private function findPhp(bool $includeArgs = true): string
    {
        if ($this->cachedPhpPath === null || $this->cachedIncludedArgs !== $includeArgs) {
            $this->cachedIncludedArgs = $includeArgs;
            $phpPath = (new PhpExecutableFinder())->find($includeArgs);
            if ($phpPath === false) {
                throw FinderException::phpExecutableNotFound();
            }
            $this->cachedPhpPath = $phpPath;
        }

        return $this->cachedPhpPath;
    }

    public function buildInitialConfigFile(): string
    {
        return $this->initialConfigBuilder->build();
    }

    public function buildMutationConfigFile(MutantInterface $mutant): string
    {
        return $this->mutationConfigBuilder->build($mutant);
    }

    public function getVersion(): string
    {
        $process = new Process(
            sprintf(
                '%s %s',
                $this->makeExecutable($this->testFrameworkFinder->find()),
                '--version'
            )
        );

        $process->mustRun();

        return $this->versionParser->parse($process->getOutput());
    }
}
