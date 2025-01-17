<?php declare(strict_types=1);

namespace Rector\Application;

use PHPStan\AnalysedCodeException;
use PHPStan\Analyser\NodeScopeResolver;
use Rector\Application\FileSystem\RemovedAndAddedFilesCollector;
use Rector\Application\FileSystem\RemovedAndAddedFilesProcessor;
use Rector\Configuration\Configuration;
use Rector\FileSystemRector\FileSystemFileProcessor;
use Rector\Testing\Application\EnabledRectorsProvider;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symplify\PackageBuilder\FileSystem\SmartFileInfo;
use Throwable;

/**
 * Rector cycle has 3 steps:
 *
 * 1. parse all files to nodes
 *
 * 2. run Rectors on all files and their nodes
 *
 * 3. print changed content to file or to string diff with "--dry-run"
 */
final class RectorApplication
{
    /**
     * @var SmartFileInfo[]
     */
    private $notParsedFiles = [];

    /**
     * @var SymfonyStyle
     */
    private $symfonyStyle;

    /**
     * @var FileSystemFileProcessor
     */
    private $fileSystemFileProcessor;

    /**
     * @var ErrorAndDiffCollector
     */
    private $errorAndDiffCollector;

    /**
     * @var Configuration
     */
    private $configuration;

    /**
     * @var FileProcessor
     */
    private $fileProcessor;

    /**
     * @var RemovedAndAddedFilesCollector
     */
    private $removedAndAddedFilesCollector;

    /**
     * @var RemovedAndAddedFilesProcessor
     */
    private $removedAndAddedFilesProcessor;

    /**
     * @var EnabledRectorsProvider
     */
    private $enabledRectorsProvider;

    /**
     * @var NodeScopeResolver
     */
    private $nodeScopeResolver;

    public function __construct(
        SymfonyStyle $symfonyStyle,
        FileSystemFileProcessor $fileSystemFileProcessor,
        ErrorAndDiffCollector $errorAndDiffCollector,
        Configuration $configuration,
        FileProcessor $fileProcessor,
        EnabledRectorsProvider $enabledRectorsProvider,
        RemovedAndAddedFilesCollector $removedAndAddedFilesCollector,
        RemovedAndAddedFilesProcessor $removedAndAddedFilesProcessor,
        NodeScopeResolver $nodeScopeResolver
    ) {
        $this->symfonyStyle = $symfonyStyle;
        $this->fileSystemFileProcessor = $fileSystemFileProcessor;
        $this->errorAndDiffCollector = $errorAndDiffCollector;
        $this->configuration = $configuration;
        $this->fileProcessor = $fileProcessor;
        $this->removedAndAddedFilesCollector = $removedAndAddedFilesCollector;
        $this->removedAndAddedFilesProcessor = $removedAndAddedFilesProcessor;
        $this->enabledRectorsProvider = $enabledRectorsProvider;
        $this->nodeScopeResolver = $nodeScopeResolver;
    }

    /**
     * @param SmartFileInfo[] $fileInfos
     */
    public function runOnFileInfos(array $fileInfos): void
    {
        $fileCount = count($fileInfos);
        if ($fileCount === 0) {
            return;
        }

        if (! $this->symfonyStyle->isVerbose() && $this->configuration->showProgressBar()) {
            // why 3? one for each cycle, so user sees some activity all the time
            $this->symfonyStyle->progressStart($fileCount * 3);
        }

        // PHPStan has to know about all files!
        $this->configurePHPStanNodeScopeResolver($fileInfos);

        // 1. parse files to nodes
        foreach ($fileInfos as $fileInfo) {
            $this->tryCatchWrapper($fileInfo, function (SmartFileInfo $smartFileInfo): void {
                $this->fileProcessor->parseFileInfoToLocalCache($smartFileInfo);
            }, 'parsing');
        }

        // active only one rule
        if ($this->configuration->getRule() !== null) {
            $rule = $this->configuration->getRule();
            $this->enabledRectorsProvider->addEnabledRector($rule);
        }

        // 2. change nodes with Rectors
        foreach ($fileInfos as $fileInfo) {
            $this->tryCatchWrapper($fileInfo, function (SmartFileInfo $smartFileInfo): void {
                $this->fileProcessor->refactor($smartFileInfo);
            }, 'refactoring');
        }

        // 3. print to file or string
        foreach ($fileInfos as $fileInfo) {
            $this->tryCatchWrapper($fileInfo, function (SmartFileInfo $smartFileInfo): void {
                $this->processFileInfo($smartFileInfo);
            }, 'printing');
        }

        if ($this->configuration->showProgressBar()) {
            $this->symfonyStyle->newLine(2);
        }

        // 4. remove and add files
        $this->removedAndAddedFilesProcessor->run();
    }

    private function tryCatchWrapper(SmartFileInfo $smartFileInfo, callable $callback, string $phase): void
    {
        $this->advance($smartFileInfo, $phase);

        try {
            if (in_array($smartFileInfo, $this->notParsedFiles, true)) {
                // we cannot process this file
                return;
            }

            $callback($smartFileInfo);
        } catch (AnalysedCodeException $analysedCodeException) {
            if ($this->configuration->shouldHideAutoloadErrors()) {
                return;
            }

            $this->notParsedFiles[] = $smartFileInfo;

            $this->errorAndDiffCollector->addAutoloadError($analysedCodeException, $smartFileInfo);
        } catch (Throwable $throwable) {
            if ($this->symfonyStyle->isVerbose()) {
                throw $throwable;
            }

            $this->errorAndDiffCollector->addThrowableWithFileInfo($throwable, $smartFileInfo);
        }
    }

    private function processFileInfo(SmartFileInfo $fileInfo): void
    {
        if ($this->removedAndAddedFilesCollector->isFileRemoved($fileInfo)) {
            // skip, because this file exists no more
            return;
        }

        $oldContent = $fileInfo->getContents();

        $newContent = $this->configuration->isDryRun() ? $this->fileProcessor->printToString(
            $fileInfo
        ) : $this->fileProcessor->printToFile($fileInfo);

        $this->errorAndDiffCollector->addFileDiff($fileInfo, $newContent, $oldContent);

        $this->fileSystemFileProcessor->processFileInfo($fileInfo);
    }

    private function advance(SmartFileInfo $smartFileInfo, string $phase): void
    {
        if ($this->symfonyStyle->isVerbose()) {
            $this->symfonyStyle->writeln(sprintf('[%s] %s', $phase, $smartFileInfo->getRealPath()));
        } elseif ($this->configuration->showProgressBar()) {
            $this->symfonyStyle->progressAdvance();
        }
    }

    /**
     * @param SmartFileInfo[] $fileInfos
     */
    private function configurePHPStanNodeScopeResolver(array $fileInfos): void
    {
        $filePaths = [];
        foreach ($fileInfos as $fileInfo) {
            $filePaths[] = $fileInfo->getRealPath();
        }

        $this->nodeScopeResolver->setAnalysedFiles($filePaths);
    }
}
