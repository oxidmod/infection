<?php
/**
 * Copyright © 2017 Maks Rafalko
 *
 * License: https://opensource.org/licenses/BSD-3-Clause New BSD License
 */
declare(strict_types=1);

namespace Infection\Mutant\Generator;

use Infection\EventDispatcher\EventDispatcher;
use Infection\Events\MutableFileProcessed;
use Infection\Events\MutationGeneratingFinished;
use Infection\Events\MutationGeneratingStarted;
use Infection\Mutation;
use Infection\Mutator\Mutator;
use Infection\TestFramework\Coverage\CodeCoverageData;
use Infection\Visitor\WrappedFunctionInfoCollectorVisitor;
use Infection\Visitor\MutationsCollectorVisitor;
use Infection\Visitor\ParentConnectorVisitor;
use PhpParser\Lexer;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class MutationsGenerator
{
    /**
     * @var array source directories
     */
    private $srcDirs;

    /**
     * @var CodeCoverageData
     */
    private $codeCoverageData;

    /**
     * @var array
     */
    private $excludeDirsOrFiles;

    /**
     * @var array
     */
    private $whitelistedMutatorNames;

    /**
     * @var int
     */
    private $whitelistedMutatorNamesCount;

    /**
     * @var Mutator[]
     */
    private $defaultMutators;

    /**
     * @var EventDispatcher
     */
    private $eventDispatcher;

    public function __construct(array $srcDirs, array $excludeDirsOrFiles, CodeCoverageData $codeCoverageData, array $defaultMutators, array $whitelistedMutatorNames, EventDispatcher $eventDispatcher)
    {
        $this->srcDirs = $srcDirs;
        $this->codeCoverageData = $codeCoverageData;
        $this->excludeDirsOrFiles = $excludeDirsOrFiles;
        $this->defaultMutators = $defaultMutators;
        $this->whitelistedMutatorNames = array_map('strtolower', $whitelistedMutatorNames);
        $this->whitelistedMutatorNamesCount = count($whitelistedMutatorNames);
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * @param bool $onlyCovered mutate only covered by tests lines of code
     * @param string $filter
     *
     * @return Mutation[]
     */
    public function generate(bool $onlyCovered, string $filter = ''): array
    {
        $files = $this->getSrcFiles($filter);
        $allFilesMutations = [[]];

        $this->eventDispatcher->dispatch(new MutationGeneratingStarted(count($files)));

        foreach ($files as $file) {
            if (!$onlyCovered || ($onlyCovered && $this->hasTests($file))) {
                $allFilesMutations[] = $this->getMutationsFromFile($file, $onlyCovered);
            }

            $this->eventDispatcher->dispatch(new MutableFileProcessed());
        }

        $this->eventDispatcher->dispatch(new MutationGeneratingFinished());

        return array_merge(...$allFilesMutations);
    }

    /**
     * @param string $filter
     *
     * @return Finder
     *
     * @throws \InvalidArgumentException
     */
    private function getSrcFiles(string $filter = ''): Finder
    {
        $finder = new Finder();
        $finder->files()->in($this->srcDirs);

        $finder->files()->name($filter ?: '*.php');

        foreach ($this->excludeDirsOrFiles as $excludePath) {
            $finder->notPath($excludePath);
        }

        return $finder;
    }

    /**
     * @param SplFileInfo $file
     * @param bool $onlyCovered mutate only covered by tests lines of code
     *
     * @return Mutation[]
     */
    private function getMutationsFromFile(SplFileInfo $file, bool $onlyCovered): array
    {
        $lexer = new Lexer([
            'usedAttributes' => [
                'comments', 'startLine', 'endLine', 'startTokenPos', 'endTokenPos', 'startFilePos', 'endFilePos',
            ],
        ]);
        $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7, $lexer);
        $traverser = new NodeTraverser();
        $mutators = $this->getMutators();

        $mutationsCollectorVisitor = new MutationsCollectorVisitor(
            $mutators,
            $file->getRealPath(),
            $this->codeCoverageData,
            $onlyCovered
        );

        $traverser->addVisitor(new ParentConnectorVisitor());
        $traverser->addVisitor(new WrappedFunctionInfoCollectorVisitor());
        $traverser->addVisitor($mutationsCollectorVisitor);

        $originalCode = $file->getContents();

        $initialStatements = $parser->parse($originalCode);

        $traverser->traverse($initialStatements);

        return $mutationsCollectorVisitor->getMutations();
    }

    private function hasTests(SplFileInfo $file): bool
    {
        return $this->codeCoverageData->hasTests($file->getRealPath());
    }

    private function getMutators(): array
    {
        if ($this->whitelistedMutatorNamesCount > 0) {
            return array_filter(
                $this->defaultMutators,
                function (Mutator $mutator): bool {
                    return in_array(strtolower($mutator->getName()), $this->whitelistedMutatorNames, true);
                }
            );
        }

        return $this->defaultMutators;
    }
}
