<?php

namespace ManaPHP;

use ManaPHP\Exception\InvalidValueException;
use ManaPHP\Exception\PreconditionException;

/**
 * Class ManaPHP\Paginator
 *
 * @package paginator
 *
 * @property \ManaPHP\Http\RequestInterface $request
 */
class Paginator extends Component implements PaginatorInterface
{
    /**
     * @var int
     */
    public $count;

    /**
     * @var int
     */
    public $size;

    /**
     * @var int
     */
    public $page;

    /**
     * @var int
     */
    public $pages;

    /**
     * @var int
     */
    public $prev;

    /**
     * @var int
     */
    public $next;

    /**
     * @var array
     */
    public $items;

    /**
     * @var int
     */
    protected $_numberOfLinks = 11;

    /**
     * @param int $number
     *
     * @return static
     */
    public function setNumberOfLinks($number)
    {
        $this->_numberOfLinks = $number;

        return $this;
    }

    /**
     * @param int $count
     * @param int $size
     * @param int $page
     *
     * @return static
     */
    public function paginate($count, $size = null, $page = null)
    {
        $this->count = (int)$count;
        $this->size = (int)($size ?: $this->request->get('size', 'int', 10));
        $this->page = (int)($page ?: $this->request->get('page', 'int', 1));
        $this->pages = (int)ceil($this->count / $this->size);
        $this->prev = ($this->page <= $this->pages && $this->page > 1) ? $this->page - 1 : -1;
        $this->next = $this->page < $this->pages ? $this->page + 1 : -1;

        return $this;
    }

    /**
     * @param  false|string $itemsName
     *
     * @return array
     */
    public function renderAsArray($itemsName = 'items')
    {
        $data = [
            'page' => $this->page,
            'size' => $this->size,
            'count' => $this->count,
            'pages' => $this->pages,
        ];

        if ($itemsName !== false) {
            $data[$itemsName] = $this->items;
        }

        return $data;
    }

    /**
     * @param string $urlTemplate
     *
     * @return string
     */
    public function renderAsHtml($urlTemplate = null)
    {
        if ($urlTemplate === null) {
            if (!$this->request->hasServer('REQUEST_URI')) {
                throw new PreconditionException('REQUEST_URI is not exist in $_SERVER');
            } else {
                $urlTemplate = $this->request->getServer('REQUEST_URI', 'ignore');
            }

            if (strpos($urlTemplate, '?page=') === false && strpos($urlTemplate, '&page=') === false) {
                $urlTemplate .= (strpos($urlTemplate, '?') === false ? '?' : '&') . 'page={page}';
            } else {
                $urlTemplate = (string)preg_replace('#([\?&]page)=\d+#', '\1={page}', $urlTemplate);
            }
        }

        if (strpos($urlTemplate, '{page}') === false) {
            throw new InvalidValueException(['`:template` url template is invalid: it must contain {page} pattern', 'template' => $urlTemplate]);
        }

        $str = PHP_EOL . '<ul class="pagination">' . PHP_EOL;
        $str .= '  <li class="first"><a href="' . str_replace('{page}', 1, $urlTemplate) . '">&lt;&lt;</a></li>' . PHP_EOL;

        if ($this->prev < 0) {
            $str .= '  <li class="prev disabled"><span>&lt;</span></li>' . PHP_EOL;
        } else {
            $str .= '  <li class="prev"><a href="' . str_replace('{page}', $this->prev, $urlTemplate) . '">&lt;</a></li>' . PHP_EOL;
        }

        $startPage = (int)min($this->page - ceil($this->_numberOfLinks / 2), $this->pages - $this->_numberOfLinks);
        $startPage = max(0, $startPage) + 1;

        $endPage = min($startPage + $this->_numberOfLinks - 1, $this->pages);

        for ($i = $startPage; $i <= $endPage; $i++) {
            if ($i === $this->page) {
                $str .= '  <li class="active"><span>' . $i . '</span></li>' . PHP_EOL;
            } else {
                $str .= '  <li><a href="' . str_replace('{page}', $i, $urlTemplate) . '">' . $i . '</a></li>' . PHP_EOL;
            }
        }

        if ($this->next < 0) {
            $str .= '  <li class="next disabled"><span>&gt;</span></li>' . PHP_EOL;
        } else {
            $str .= '  <li class="next"><a href="' . str_replace('{page}', $this->next, $urlTemplate) . '">&gt;</a></li>' . PHP_EOL;
        }

        $str .= '  <li class="last"><a href="' . str_replace('{page}', $this->pages, $urlTemplate) . '">&gt;&gt;</a></li>' . PHP_EOL;
        $str .= '</ul>' . PHP_EOL;
        return $str;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        try {
            return $this->renderAsHtml();
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * @return array
     */
    public function jsonSerialize()
    {
        return $this->renderAsArray();
    }
}