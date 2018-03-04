<?php
namespace ManaPHP\Dom;

class CssToXpath
{
    /**
     * Transform CSS expression to XPath
     *
     * @param  string $path_src
     *
     * @return string
     */
    public function transform($path_src)
    {
        $path = (string)$path_src;
        if (strpos($path, ',') !== false) {
            $paths = explode(',', $path);
            $expressions = [];
            foreach ($paths as $path) {
                $xpath = $this->transform(trim($path));
                if (is_string($xpath)) {
                    $expressions[] = $xpath;
                } elseif (is_array($xpath)) {
                    /** @noinspection SlowArrayOperationsInLoopInspection */
                    $expressions = array_merge($expressions, $xpath);
                }
            }
            return implode('|', $expressions);
        }

        $paths = ['//'];
        $path = preg_replace('|\s+>\s+|', '>', $path);
        $segments = preg_split('/\s+/', $path);
        foreach ($segments as $key => $segment) {
            $pathSegment = static::_tokenize($segment);
            if (0 === $key) {
                if (0 === strpos($pathSegment, '[contains(')) {
                    $paths[0] .= '*' . ltrim($pathSegment, '*');
                } else {
                    $paths[0] .= $pathSegment;
                }
                continue;
            }
            if (0 === strpos($pathSegment, '[contains(')) {
                foreach ($paths as $pathKey => $xpath) {
                    $paths[$pathKey] .= '//*' . ltrim($pathSegment, '*');
                    $paths[] = $xpath . $pathSegment;
                }
            } else {
                foreach ($paths as $pathKey => $xpath) {
                    $paths[$pathKey] .= '//' . $pathSegment;
                }
            }
        }

        if (1 === count($paths)) {
            return $paths[0];
        }
        return implode('|', $paths);
    }

    /**
     * Tokenize CSS expressions to XPath
     *
     * @param  string $expression_src
     *
     * @return string
     */
    protected static function _tokenize($expression_src)
    {
        // Child selectors
        $expression = str_replace('>', '/', $expression_src);

        // IDs
        $expression = preg_replace('|#([a-z][a-z0-9_-]*)|i', '[@id=\'$1\']', $expression);
        $expression = preg_replace('|(?<![a-z0-9_-])(\[@id=)|i', '*$1', $expression);

        // arbitrary attribute strict equality
        $expression = preg_replace_callback(
            '|\[@?([a-z0-9_-]+)=[\'"]([^\'"]+)[\'"]\]|i',
            function ($matches) {
                return '[@' . strtolower($matches[1]) . "='" . $matches[2] . "']";
            },
            $expression
        );

        // arbitrary attribute contains full word
        $expression = preg_replace_callback(
            '|\[([a-z0-9_-]+)~=[\'"]([^\'"]+)[\'"]\]|i',
            function ($matches) {
                return "[contains(concat(' ', normalize-space(@" . strtolower($matches[1]) . "), ' '), ' "
                    . $matches[2] . " ')]";
            },
            $expression
        );

        // arbitrary attribute contains specified content
        $expression = preg_replace_callback(
            '|\[([a-z0-9_-]+)\*=[\'"]([^\'"]+)[\'"]\]|i',
            function ($matches) {
                return '[contains(@' . strtolower($matches[1]) . ", '"
                    . $matches[2] . "')]";
            },
            $expression
        );

        // Classes
        if (false === strpos($expression, '[@')) {
            $expression = preg_replace(
                '|\.([a-z][a-z0-9_-]*)|i',
                "[contains(concat(' ', normalize-space(@class), ' '), ' \$1 ')]",
                $expression
            );
        }

        /** ZF-9764 -- remove double asterisk */
        $expression = str_replace('**', '*', $expression);

        return $expression;
    }
}