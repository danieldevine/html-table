<?php

declare(strict_types=1);

namespace Bakame\HtmlTable;

use ArrayIterator;
use Closure;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use Iterator;
use League\Csv\ResultSet;
use League\Csv\SyntaxError;
use SimpleXMLElement;
use Stringable;

use function array_fill;
use function array_filter;
use function array_key_exists;
use function array_merge;
use function array_shift;
use function array_unique;
use function fclose;
use function fopen;
use function in_array;
use function is_resource;
use function libxml_clear_errors;
use function libxml_get_errors;
use function libxml_use_internal_errors;
use function preg_match;
use function restore_error_handler;
use function set_error_handler;
use function stream_get_contents;
use function strtolower;

final class Parser
{
    private const CELL_TAGS = ['th', 'td'];
    private const HEADER_ROW_ATTRIBUTE_NAME = 'data-html-parser-select-header-row';

    /**
     * @param array<string> $tableHeader
     * @param array<string, int> $includedSections
     */
    private function __construct(
        private readonly string $tableExpression,
        private readonly ?string $caption,
        private readonly array $tableHeader,
        private readonly bool $ignoreTableHeader,
        private readonly string $tableHeaderExpression,
        private readonly array $includedSections,
        private readonly ?Closure $formatter,
        private readonly bool $throwOnXmlErrors,
    ) {
    }

    public static function new(): self
    {
        return new self(
            '(//table)[1]',
            null,
            [],
            false,
            '(//table/thead/tr)[1]',
            [Section::tbody->value => 1, Section::tr->value => 1, Section::tfoot->value => 1],
            null,
            false,
        );
    }

    public function tableXPathPosition(string $expression): self
    {
        set_error_handler(fn (int $errno, string $errstr, string $errfile, int $errline) => true);
        $newInstace = match (true) {
            $expression === $this->tableExpression => $this,
            false === (new DOMXPath(new DOMDocument()))->query($expression) => throw new ParserError('The xpath expression `'.$expression.'` is invalie.'),
            default => new self(
                $expression,
                $this->caption,
                $this->tableHeader,
                $this->ignoreTableHeader,
                $this->tableHeaderExpression,
                $this->includedSections,
                $this->formatter,
                $this->throwOnXmlErrors,
            ),
        };
        restore_error_handler();

        return $newInstace;
    }

    /**
     * @throws ParserError
     */
    public function tablePosition(int|string $positionOrId): self
    {
        return self::tableXPathPosition(match (true) {
            is_string($positionOrId) => match (true) {
                1 === preg_match(",\s,", $positionOrId) => throw new ParserError("The id attribute's value must not contain whitespace (spaces, tabs etc.)"),
                default => '(//table[@id="'.$positionOrId.'"])[1]',
            },
            $positionOrId < 0 => throw new ParserError('the table offset must be a positive integer or the table id attribute value.'),
            default => '(//table)['.($positionOrId + 1).']',
        });
    }

    /**
     * @param array<string> $headerRow
     *
     * @throws ParserError
     */
    public function tableHeader(array $headerRow): self
    {
        return match (true) {
            $headerRow === $this->tableHeader => $this,
            $headerRow !== ($filteredHeader = array_filter($headerRow, is_string(...))) => throw new ParserError('The header record contains non string colum names.'),
            $headerRow !== array_unique($filteredHeader) => throw ParserError::dueToDuplicateHeaderColumnNames($headerRow),
            default => new self(
                $this->tableExpression,
                $this->caption,
                $headerRow,
                $this->ignoreTableHeader,
                $this->tableHeaderExpression,
                $this->includedSections,
                $this->formatter,
                $this->throwOnXmlErrors,
            ),
        };
    }

    public function ignoreTableHeader(): self
    {
        return match ($this->ignoreTableHeader) {
            true => $this,
            false => new self(
                $this->tableExpression,
                $this->caption,
                $this->tableHeader,
                true,
                $this->tableHeaderExpression,
                $this->includedSections,
                $this->formatter,
                $this->throwOnXmlErrors,
            ),
        };
    }

    public function resolveTableHeader(): self
    {
        return match ($this->ignoreTableHeader) {
            false => $this,
            true => new self(
                $this->tableExpression,
                $this->caption,
                $this->tableHeader,
                false,
                $this->tableHeaderExpression,
                $this->includedSections,
                $this->formatter,
                $this->throwOnXmlErrors,
            ),
        };
    }

    /**
     * @param int<0, max> $offset
     */
    public function tableHeaderPosition(Section $section, int $offset = 0): self
    {
        $expression = $section->xpathRow($offset);

        return match ($this->tableHeaderExpression) {
            $expression  => $this,
            default => new self(
                $this->tableExpression,
                $this->caption,
                $this->tableHeader,
                $this->ignoreTableHeader,
                $expression,
                $this->includedSections,
                $this->formatter,
                $this->throwOnXmlErrors,
            ),
        };
    }

    public function includeSection(Section ...$sections): self
    {
        $includedSections = array_reduce(
            $sections,
            function (array $carry, Section $section) {
                $carry[$section->value] = 1;

                return $carry;
            },
            $this->includedSections
        );

        return match ($this->includedSections) {
            $includedSections => $this,
            default => new self(
                $this->tableExpression,
                $this->caption,
                $this->tableHeader,
                $this->ignoreTableHeader,
                $this->tableHeaderExpression,
                $includedSections,
                $this->formatter,
                $this->throwOnXmlErrors,
            ),
        };
    }

    public function excludeSection(Section ...$sections): self
    {
        $includedSections = array_reduce(
            $sections,
            function (array $carry, Section $section) {
                unset($carry[$section->value]);

                return $carry;
            },
            $this->includedSections
        );

        return match ($this->includedSections) {
            $includedSections => $this,
            default => new self(
                $this->tableExpression,
                $this->caption,
                $this->tableHeader,
                $this->ignoreTableHeader,
                $this->tableHeaderExpression,
                $includedSections,
                $this->formatter,
                $this->throwOnXmlErrors,
            ),
        };
    }

    public function failOnXmlErrors(): self
    {
        return match ($this->throwOnXmlErrors) {
            true => $this,
            false => new self(
                $this->tableExpression,
                $this->caption,
                $this->tableHeader,
                $this->ignoreTableHeader,
                $this->tableHeaderExpression,
                $this->includedSections,
                $this->formatter,
                true,
            ),
        };
    }

    public function ignoreXmlErrors(): self
    {
        return match ($this->throwOnXmlErrors) {
            false => $this,
            true => new self(
                $this->tableExpression,
                $this->caption,
                $this->tableHeader,
                $this->ignoreTableHeader,
                $this->tableHeaderExpression,
                $this->includedSections,
                $this->formatter,
                false,
            ),
        };
    }

    public function withFormatter(Closure $formatter): self
    {
        return new self(
            $this->tableExpression,
            $this->caption,
            $this->tableHeader,
            $this->ignoreTableHeader,
            $this->tableHeaderExpression,
            $this->includedSections,
            $formatter,
            $this->throwOnXmlErrors,
        );
    }

    public function withoutFormatter(): self
    {
        return match (null) {
            $this->formatter => $this,
            default => new self(
                $this->tableExpression,
                $this->caption,
                $this->tableHeader,
                $this->ignoreTableHeader,
                $this->tableHeaderExpression,
                $this->includedSections,
                null,
                $this->throwOnXmlErrors,
            ),
        };
    }

    public function tableCaption(?string $caption): self
    {
        return match ($this->caption) {
            $caption => $this,
            default => new self(
                $this->tableExpression,
                $caption,
                $this->tableHeader,
                $this->ignoreTableHeader,
                $this->tableHeaderExpression,
                $this->includedSections,
                $this->formatter,
                $this->throwOnXmlErrors,
            ),
        };
    }

    /**
     * @param resource|string $filenameOrStream
     * @param resource|null $filenameContext
     *
     * @throws ParserError
     * @throws SyntaxError
     */
    public function parseFile(mixed $filenameOrStream, $filenameContext = null): Table
    {
        if (is_resource($filenameOrStream)) {
            return $this->parseHtml($this->streamToString($filenameOrStream));
        }

        set_error_handler(fn (int $errno, string $errstr, string $errfile, int $errline) => true);
        $resource = fopen(...match ($filenameContext) {
            null => [$filenameOrStream, 'r'],
            default => [$filenameOrStream, 'r', false, $filenameContext],
        });
        restore_error_handler();

        if (!is_resource($resource)) {
            throw new ParserError('`'.$filenameOrStream.'`: failed to open stream: No such file or directory.');
        }

        $html = $this->streamToString($resource);
        fclose($resource);

        return $this->parseHtml($html);
    }

    /**
     * @throws ParserError
     * @throws SyntaxError
     */
    public function parseHtml(DOMDocument|DOMElement|SimpleXMLElement|Stringable|string $source): Table
    {
        /** @var DOMNodeList<DOMElement> $query */
        $query = (new DOMXPath($this->sourceToDomDocument($source)))->query($this->tableExpression);
        $table = $query->item(0);
        if (!$table instanceof DOMElement) {
            throw new ParserError('The HTML table could not be found in the submitted html.');
        }

        $tagName = strtolower($table->nodeName);
        if ('table' !== $tagName) {
            throw new ParserError('Expected a table element to be selected; received `'.$tagName.'` instead.');
        }

        $xpath = new DOMXPath($this->sourceToDomDocument($table));
        $header = match (true) {
            [] !== $this->tableHeader => $this->tableHeader,
            $this->ignoreTableHeader => [],
            default => $this->extractTableHeader($xpath),
        };

        /** @var DOMNodeList<DOMElement> $result */
        $result = $xpath->query('(//caption)[1]');
        $caption = $result->item(0)?->nodeValue ?? $this->caption;

        return new Table(new ResultSet($this->extractTableContents($xpath, $header), array_values($header)), $caption);
    }

    /**
     * @param resource $stream
     *
     * @throws ParserError
     */
    private function streamToString($stream): string
    {
        set_error_handler(fn (int $errno, string $errstr, string $errfile, int $errline) => true);
        $html = stream_get_contents($stream);
        restore_error_handler();

        return match (false) {
            $html => throw new ParserError('The resource could not be read.'),
            default => $html,
        };
    }

    /**
     * @throws ParserError
     */
    private function sourceToDomDocument(DOMDocument|SimpleXMLElement|DOMElement|Stringable|string $document): DOMDocument
    {
        if ($document instanceof DOMDocument) {
            return $document;
        }

        $dom = new DOMDocument();
        if ($document instanceof DOMElement) {
            $dom->appendChild($dom->importNode($document, true));

            return $dom;
        }

        if ($document instanceof SimpleXMLElement) {
            $dom->appendChild($dom->importNode(dom_import_simplexml($document), true));

            return $dom;
        }

        libxml_use_internal_errors(true);
        $dom->loadHTML((string) $document);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        return match (true) {
            $this->throwOnXmlErrors && [] !== $errors => throw ParserError::dueToLibXmlErrors($errors),
            default => $dom,
        };
    }

    /**
     * @return array<string>
     */
    private function extractTableHeader(DOMXPath $xpath): array
    {
        /** @var DOMNodeList<DOMElement> $query */
        $query = $xpath->query($this->tableHeaderExpression);
        /** @var DOMElement|null $tr */
        $tr = $query->item(0);

        return match (null) {
            $tr => [],
            default => $this->extractHeaderRow($tr),
        };
    }

    /**
     * @param array<string> $header
     */
    private function extractTableContents(DOMXPath $xpath, array $header): Iterator
    {
        /** @var DOMNodeList<DOMElement> $query */
        $query = $xpath->query('//table');
        /** @var DOMElement $table */
        $table = $query->item(0);
        $iterator = new ArrayIterator();
        $header =  $this->tableHeader($header)->tableHeader;
        $rowSpan = [];
        foreach ($table->childNodes as $childNode) {
            if (!$childNode instanceof DOMElement) {
                continue;
            }

            $section = Section::tryFrom(strtolower($childNode->nodeName));
            if (!$this->isIncludedSection($section)) {
                continue;
            }

            if (Section::tr === $section && null !== ($record = $this->filterRecord($childNode))) {
                $iterator->append($this->formatRecord($this->extractRecord($record, $rowSpan), $header));
                continue;
            }

            $rowSpanSection = [];
            foreach ($childNode->childNodes as $tr) {
                if (null !== ($record = $this->filterRecord($tr))) {
                    $iterator->append($this->formatRecord($this->extractRecord($record, $rowSpanSection), $header));
                }
            }
        }

        return $iterator;
    }

    private function isIncludedSection(?Section $nodeName): bool
    {
        return array_key_exists($nodeName?->value ?? '', $this->includedSections);
    }

    private function filterRecord(DOMNode $tr): ?DOMElement
    {
        return match (true) {
            !$tr instanceof DOMElement,
            'tr' !== strtolower($tr->nodeName),
            $tr->hasAttribute(self::HEADER_ROW_ATTRIBUTE_NAME) => null,
            default => $tr,
        };
    }

    /**
     * @return array<string>
     */
    private function extractHeaderRow(DOMElement $tr): array
    {
        $headerRow = $this->extractRecord($tr);
        if ([] !== $headerRow) {
            $tr->setAttribute(self::HEADER_ROW_ATTRIBUTE_NAME, 'true');
        }

        return $headerRow;
    }

    /**
     * @param array<int, array<array<string|null>>> $rowSpanIndices
     *
     * @return array<string>
     */
    private function extractRecord(DOMElement $tr, array &$rowSpanIndices = []): array
    {
        $spanSize = function (DOMElement $node, string $attr): int {
            $span = (int) $node->getAttribute($attr);

            return match (true) {
                2 > $span, 1000 < $span => 1,
                default => $span,
            };
        };

        $row = [];
        foreach ($tr->childNodes as $index => $node) {
            if (array_key_exists($index, $rowSpanIndices)) {
                $row = array_merge($row, array_shift($rowSpanIndices[$index]));  /* @phpstan-ignore-line */
                if ([] === $rowSpanIndices[$index]) {
                    unset($rowSpanIndices[$index]);
                }
            }

            if ($node instanceof DOMElement && in_array(strtolower($node->nodeName), self::CELL_TAGS, true)) {
                $cells = array_fill(0, $spanSize($node, 'colspan'), $node->nodeValue.'');
                $row = array_merge($row, $cells);
                $rowSpanCount = $spanSize($node, 'rowspan');
                if (1 < $rowSpanCount) {
                    $rowSpanIndices[$index] = array_fill(0, $rowSpanCount - 1, $cells);
                }
            }
        }

        $index ??= -2;
        ++$index;
        if (array_key_exists($index, $rowSpanIndices)) {
            $row = array_merge($row, array_shift($rowSpanIndices[$index])); /* @phpstan-ignore-line */
            if ([] === $rowSpanIndices[$index]) {
                unset($rowSpanIndices[$index]);
            }
        }

        return $row;
    }

    /**
     * @param array<string> $record
     * @param array<string> $header
     *
     * @return array<string>
     */
    private function formatRecord(array $record, array $header): array
    {
        $record = match ([]) {
            $header => $record,
            default => $this->combineArray($record, $header),
        };

        return match (null) {
            $this->formatter => $record,
            default => ($this->formatter)($record),
        };
    }

    /**
     * @param array<string> $record
     * @param array<string> $header
     *
     * @return array<string, string|null>
     */
    private function combineArray(array $record, array $header): array
    {
        $row = [];
        foreach ($header as $offset => $value) {
            $row[$value] = $record[$offset] ?? null;
        }

        return $row;
    }
}
