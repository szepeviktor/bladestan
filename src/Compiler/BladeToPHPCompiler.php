<?php

declare(strict_types=1);

namespace Bladestan\Compiler;

use Bladestan\Blade\PhpLineToTemplateLineResolver;
use Bladestan\Exception\ShouldNotHappenException;
use Bladestan\PhpParser\ArrayStringToArrayConverter;
use Bladestan\PhpParser\NodeVisitor\AddLoopVarTypeToForeachNodeVisitor;
use Bladestan\PhpParser\NodeVisitor\DeleteInlineHTML;
use Bladestan\PhpParser\NodeVisitor\TransformEach;
use Bladestan\PhpParser\NodeVisitor\TransformIncludes;
use Bladestan\PhpParser\SimplePhpParser;
use Bladestan\TemplateCompiler\NodeFactory\VarDocNodeFactory;
use Bladestan\ValueObject\AbstractInlinedElement;
use Bladestan\ValueObject\ComponentAndVariables;
use Bladestan\ValueObject\IncludedViewAndVariables;
use Bladestan\ValueObject\PhpFileContentsWithLineMap;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\AnonymousComponent;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Factory as EnvView;
use InvalidArgumentException;
use PhpParser\Error as ParserError;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use ReflectionClass;
use ReflectionNamedType;

final class BladeToPHPCompiler
{
    /**
     * @see https://regex101.com/r/dpKuqR/1
     * @var string
     */
    private const VIEW_INCLUDE_REGEX = '/echo \$__env->make\( *\'([^\']+?)\' *(?:, *(\[.*?\]|\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*))?.*?\)->render\(\);/s';

    /**
     * @see https://regex101.com/r/Fo7sHW/1
     * @var string
     */
    private const COMPONENT_REGEX = '/if \(isset\(\$component\)\).+?\$component = (.*?)::resolve\(.+?\$component->withAttributes\(\[.*?\]\);/s';

    /**
     * @see https://regex101.com/r/XGSsgA/1
     * @var string
     */
    private const ANONYMOUS_COMPONENT_REGEX = '/Illuminate\\\\View\\\\AnonymousComponent::resolve\(\[\'view\' => \'([^\']+)\', *\'data\' => (\[.*?\])\] \+ \(isset\(\$attributes\)/s';

    /**
     * @see https://regex101.com/r/B3BbxW/1
     * @var string
     */
    private const BACKED_COMPONENT_REGEX = '/if \(isset\(\$component\)\).+?\$component = (.*?)::resolve\((\[(?:.*?)?\]) .+?\$component->withAttributes\(\[.*?\]\);/s';

    /**
     * @see https://regex101.com/r/mt3PUM/1
     * @var string
     */
    private const COMPONENT_END_REGEX = '/echo \$__env->renderComponent\(\);.+?unset\(\$__componentOriginal.+?}/s';

    /**
     * @var list<array{0: string, 1: string}>
     */
    private array $errors;

    public function __construct(
        private readonly Filesystem $fileSystem,
        private readonly BladeCompiler $bladeCompiler,
        private readonly Standard $printerStandard,
        private readonly VarDocNodeFactory $varDocNodeFactory,
        private readonly ViewFactory $viewFactory,
        private readonly PhpLineToTemplateLineResolver $phpLineToTemplateLineResolver,
        private readonly ArrayStringToArrayConverter $arrayStringToArrayConverter,
        private readonly FileNameAndLineNumberAddingPreCompiler $fileNameAndLineNumberAddingPreCompiler,
        private readonly LivewireTagCompiler $livewireTagCompiler,
        private readonly SimplePhpParser $simplePhpParser,
    ) {
    }

    /**
     * @param array<string> $allVariablesList
     */
    public function inlineInclude(
        string $filePath,
        string $fileContents,
        array $allVariablesList,
        bool $addPHPOpeningTag
    ): string {
        // Precompile contents to add template file name and line numbers
        $fileContents = $this->fileNameAndLineNumberAddingPreCompiler
            ->completeLineCommentsToBladeContents($filePath, $fileContents);

        // Extract PHP content from HTML and PHP mixed content
        $rawPhpContent = '';
        try {
            /** @throws InvalidArgumentException */
            $compiledBlade = $this->bladeCompiler->compileString($fileContents);
            /** @throws ParserError */
            $stmts = $this->simplePhpParser->parse($compiledBlade);
            $stmts = $this->traverseStmtsWithVisitors($stmts, [
                new DeleteInlineHTML(),
                new AddLoopVarTypeToForeachNodeVisitor(),
                new TransformEach(),
                new TransformIncludes(),
            ]);
            if ($addPHPOpeningTag) {
                $rawPhpContent = $this->printerStandard->prettyPrintFile($stmts) . "\n";
            } else {
                $rawPhpContent = $this->printerStandard->prettyPrint($stmts) . "\n";
            }
        } catch (ParserError) {
            $filePath = $this->fileNameAndLineNumberAddingPreCompiler->getRelativePath($filePath);
            $this->errors[] = ["View [{$filePath}] contains syntx errors.", 'bladestan.parsing'];
        } catch (InvalidArgumentException $exception) {
            $this->errors[] = [$exception->getMessage(), 'bladestan.missing'];
        }

        $rawPhpContent = $this->livewireTagCompiler->replace($rawPhpContent);

        // Recursively fetch and compile includes
        foreach ($this->getIncludes($rawPhpContent) as $include) {
            try {
                /** @throws InvalidArgumentException */
                $includedFilePath = $this->viewFactory->getFinder()
                    ->find($include->includedViewName);
                $includedContent = $this->fileSystem->get($includedFilePath);
            } catch (InvalidArgumentException|FileNotFoundException $exception) {
                $includedFilePath = '';
                $includedContent = '';
                $this->errors[] = [$exception->getMessage(), 'bladestan.missing'];
            }

            $includedContent = $include->preprocessTemplate($includedContent);
            $includedContent = $this->inlineInclude(
                $includedFilePath,
                $includedContent,
                $include->getInnerScopeVariableNames($allVariablesList),
                false
            );

            $rawPhpContent = str_replace(
                $include->rawPhpContent,
                $include->generateInlineRepresentation($includedContent),
                $rawPhpContent
            );
        }

        return $rawPhpContent;
    }

    public function bubbleUpImports(string $rawPhpContent): string
    {
        preg_match_all('/(?<=^|\s)use .+?;/', $rawPhpContent, $imports);
        foreach ($imports[0] as $import) {
            $rawPhpContent = str_replace($import, '', $rawPhpContent);
        }

        $import = implode("\n", array_unique($imports[0]));
        return str_replace("<?php\n", "<?php\n{$import}", $rawPhpContent);
    }

    public function resolveComponents(string $rawPhpContent): string
    {
        preg_match_all(self::BACKED_COMPONENT_REGEX, $rawPhpContent, $components, PREG_SET_ORDER);
        foreach ($components as $component) {
            $class = $component[1];
            $arrayString = trim($component[2], ' ,');
            $attributes = $this->arrayStringToArrayConverter->convert($arrayString);

            // Resolve any additional required arguments
            if (class_exists($class) && method_exists($class, '__construct')) {
                $parameters = (new ReflectionClass($class))->getMethod('__construct')
                    ->getParameters();
                foreach ($parameters as $parameter) {
                    if ($parameter->isDefaultValueAvailable()) {
                        continue;
                    }

                    $paramName = $parameter->getName();
                    if (isset($attributes[$paramName])) {
                        continue;
                    }

                    $paramType = $parameter->getType();
                    if (! $paramType instanceof ReflectionNamedType) {
                        continue;
                    }

                    if ($paramType->allowsNull()) {
                        $attributes[$paramName] = 'null';
                        continue;
                    }

                    $paramClass = $paramType->getName();
                    if (class_exists($paramClass) || interface_exists($paramClass)) {
                        $attributes[$paramName] = "resolve({$paramClass}::class)";
                        continue;
                    }
                }
            }

            $attrString = collect($attributes)
                ->map(fn (string $value, string $attribute): string => "{$attribute}: {$value}")
                ->implode(', ');
            $rawPhpContent = str_replace($component[0], "\$component = new {$class}({$attrString});", $rawPhpContent);
        }

        return preg_replace(
            self::COMPONENT_END_REGEX,
            '',
            $rawPhpContent
        ) ?? throw new ShouldNotHappenException('preg_replace error');
    }

    /**
     * @param array<string, Type> $variablesAndTypes
     */
    public function compileContent(
        string $filePath,
        string $fileContents,
        array $variablesAndTypes
    ): PhpFileContentsWithLineMap {
        $this->errors = [];
        $variablesAndTypes += [
            '__env' => new ObjectType(EnvView::class),
            'errors' => new ObjectType(ViewErrorBag::class),
        ];

        $allVariablesList = array_keys($variablesAndTypes);

        $rawPhpContent = $this->inlineInclude($filePath, $fileContents, $allVariablesList, true);
        $rawPhpContent = $this->resolveComponents($rawPhpContent);
        $rawPhpContent = $this->bubbleUpImports($rawPhpContent);

        $decoratedPhpContent = $this->decoratePhpContent($rawPhpContent, $variablesAndTypes);
        $phpLinesToTemplateLines = $this->phpLineToTemplateLineResolver->resolve($decoratedPhpContent);
        return new PhpFileContentsWithLineMap($decoratedPhpContent, $phpLinesToTemplateLines, $this->errors);
    }

    /**
     * Add @var docs to top of file
     *
     * @param array<string, Type> $variablesAndTypes
     */
    private function decoratePhpContent(string $phpContent, array $variablesAndTypes): string
    {
        $stmts = $this->simplePhpParser->parse($phpContent);

        $docNodes = $this->varDocNodeFactory->createDocNodes($variablesAndTypes);
        $stmts = array_merge($docNodes, $stmts);

        return $this->printerStandard->prettyPrintFile($stmts) . PHP_EOL;
    }

    /**
     * @param Stmt[] $stmts
     * @param NodeVisitorAbstract[] $nodeVisitors
     * @return Node[]
     */
    private function traverseStmtsWithVisitors(array $stmts, array $nodeVisitors): array
    {
        $nodeTraverser = new NodeTraverser();
        foreach ($nodeVisitors as $nodeVisitor) {
            $nodeTraverser->addVisitor($nodeVisitor);
        }

        return $nodeTraverser->traverse($stmts);
    }

    /**
     * @return list<AbstractInlinedElement>
     */
    private function getIncludes(string $compiled): array
    {
        $return = [];

        preg_match_all(self::VIEW_INCLUDE_REGEX, $compiled, $includes, PREG_SET_ORDER);
        foreach ($includes as $include) {
            $data = $include[2] ?? '';
            $extract = null;
            if (preg_match('#^\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$#s', $data) === 1) {
                $extract = $data;
                $data = [];
            } else {
                $data = $this->arrayStringToArrayConverter->convert($data);
                // Filter out attributes
                $data = array_filter($data, function (string|int $key): bool {
                    return is_string($key) && preg_match('#^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$#s', $key) === 1;
                }, ARRAY_FILTER_USE_KEY);
            }

            $return[] = new IncludedViewAndVariables($include[0], $include[1], $data, $extract);
        }

        preg_match_all(self::COMPONENT_REGEX, $compiled, $components, PREG_SET_ORDER);
        foreach ($components as $component) {
            if ($component[1] !== AnonymousComponent::class) {
                continue;
            }

            preg_match(self::ANONYMOUS_COMPONENT_REGEX, $component[0], $matches);

            $view = $matches[1] ?? '';
            if ($view === '') {
                continue;
            }

            $includeVariables = $matches[2] ?? '[]';
            $includeVariables = $this->arrayStringToArrayConverter->convert($includeVariables);
            // Filter out attributes
            $includeVariables = array_filter($includeVariables, function (string|int $key): bool {
                return is_string($key) && preg_match('#^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$#s', $key) === 1;
            }, ARRAY_FILTER_USE_KEY);

            $return[] = new ComponentAndVariables(
                $component[0],
                $view,
                $includeVariables,
                $this->arrayStringToArrayConverter
            );
        }

        return $return;
    }
}
