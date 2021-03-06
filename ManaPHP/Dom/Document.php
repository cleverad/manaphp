<?php
namespace ManaPHP\Dom;

use ManaPHP\Component;
use ManaPHP\Dom\Document\Exception as DocumentException;

/**
 * Class Document
 *
 * @package ManaPHP\Dom
 *
 * @property \ManaPHP\Curl\Easy $httpClient
 */
class Document extends Component
{
    /**
     * @var string
     */
    protected $_sourceUrl;

    /**
     * @var string
     */
    protected $_baseUrl;

    /**
     * @var string
     */
    protected $_str;

    /**
     * @var \DOMDocument
     */
    protected $_dom;

    /**
     * @var \ManaPHP\Dom\Query
     */
    protected $_query;

    /**
     * @var array
     */
    protected $_domErrors = [];

    /**
     * Document constructor.
     *
     * @param string $str
     *
     * @throws \ManaPHP\Curl\ConnectionException
     */
    public function __construct($str = null)
    {
        if ($str !== null) {
            $this->load($str);
        }
    }

    /**
     * @param string $str
     *
     * @return static
     * @throws \ManaPHP\Curl\ConnectionException
     */
    public function load($str)
    {
        if (preg_match('#^https?://#', $str)) {
            $this->loadUrl($str);
        } elseif ($str[0] === '@' || $str[0] === '/' || $str[1] === ':') {
            $this->loadFile($str);
        } else {
            $this->loadString($str);
        }

        return $this;
    }

    /**
     * @param string $file
     *
     * @return static
     */
    public function loadFile($file)
    {
        $this->_sourceUrl = $file;
        $str = $this->filesystem->fileGet($file);
        return $this->loadString($str);
    }

    /**
     * @param string $url
     *
     * @return static
     * @throws \ManaPHP\Curl\ConnectionException
     */
    public function loadUrl($url)
    {
        $str = $this->httpClient->get($url)->body;
        return $this->loadString($str, $url);
    }

    /**
     * @param string $str
     * @param string $url
     *
     * @return static
     */
    public function loadString($str, $url = null)
    {
        $this->_str = $str;

        $this->_dom = new \DOMDocument();
        $this->_dom->strictErrorChecking = false;

        libxml_clear_errors();
        $old_use_internal_errors = libxml_use_internal_errors(true);
        $old_disable_entity_loader = libxml_disable_entity_loader(true);

        /** @noinspection SubStrUsedAsStrPosInspection */
        if (substr($str, 0, 5) === '<?xml') {
            $r = $this->_dom->loadXML($str);
        } else {
            $r = $this->_dom->loadHTML($str, LIBXML_HTML_NODEFDTD);
        }

        $this->_domErrors = libxml_get_errors();
        libxml_clear_errors();

        libxml_disable_entity_loader($old_disable_entity_loader);
        libxml_use_internal_errors($old_use_internal_errors);

        if (!$r) {
            /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
            throw new DocumentException('xx');
        }

        $this->_query = new Query($this->_dom);

        $this->_sourceUrl = $url;
        $this->_baseUrl = $this->_getBaseUrl() ?: $this->_sourceUrl;

        return $this;
    }

    /**
     * @param  bool $raw
     *
     * @return string
     */
    public function getString($raw = true)
    {
        return $raw ? $this->_str : $this->_dom->saveHTML($this->_dom->documentElement);
    }

    /**
     * @return array
     */
    public function getErrors()
    {
        return $this->_domErrors;
    }

    /**
     * @param string $file
     *
     * @return static
     */
    public function save($file)
    {
        $this->filesystem->filePut($file, $this->getString());

        return $this;
    }

    /**
     * @return \ManaPHP\Dom\Query
     */
    public function getQuery()
    {
        return $this->_query;
    }

    /**
     * @return string
     */
    protected function _getBaseUrl()
    {
        foreach ($this->_dom->getElementsByTagName('base') as $node) {
            /**
             * @var \DOMElement $node
             */
            if (!$node->hasAttribute('href')) {
                continue;
            }

            $href = $node->getAttribute('href');

            if (preg_match('#^https?://#', $href)) {
                return $href;
            } elseif ($href[0] === '/') {
                return substr($this->_sourceUrl, 0, strpos($this->_sourceUrl, '/', 10)) . $href;
            } else {
                return substr($this->_sourceUrl, 0, strrpos($this->_sourceUrl, '/', 10) + 1) . $href;
            }
        }

        return null;
    }

    /**
     * @param \DOMElement $node
     *
     * @return string
     */
    public function saveHtml($node = null)
    {
        if ($node) {
            return $node->ownerDocument->saveHTML($node);
        } else {
            return $this->_dom->saveHTML($this->_dom);
        }
    }

    /**
     * @param string $url
     *
     * @return string
     */
    public function absolutizeUrl($url)
    {
        if (preg_match('#^https?://#i', $url) || strpos($url, 'javascript:') === 0) {
            return $url;
        }

        if ($url === '') {
            return $this->_baseUrl;
        } elseif ($url[0] === '/') {
            return substr($this->_baseUrl, 0, strpos($this->_baseUrl, '/', 10)) . $url;
        } elseif ($url[0] === '#') {
            if (($pos = strrpos($this->_sourceUrl, '#')) === false) {
                return $this->_sourceUrl . $url;
            } else {
                return substr($this->_sourceUrl, 0, $pos) . $url;
            }
        } else {
            return substr($this->_baseUrl, 0, strrpos($this->_baseUrl, '/', 10) + 1) . $url;
        }
    }

    /**
     * @param string      $selector
     * @param \DOMElement $context
     *
     * @return static
     */
    public function absolutizeAHref($selector = null, $context = null)
    {
        /**
         * @var \DOMElement $item
         */
        if ($selector) {
            foreach ($this->_query->xpath($selector, $context) as $item) {
                if ($item->nodeName === 'a') {
                    $item->setAttribute('href', $this->absolutizeUrl($item->getAttribute('href')));
                } else {
                    $this->absolutizeAHref(null, $item);
                }
            }
        } else {
            foreach ($this->_query->xpath("descendant:://a[not(starts-with(@href, 'http'))]", $context) as $item) {
                $item->setAttribute('href', $this->absolutizeUrl($item->getAttribute('href')));
            }
        }

        return $this;
    }

    /**
     * @param string      $selector
     * @param \DOMElement $context
     * @param string      $attr
     *
     * @return static
     */
    public function absolutizeImgSrc($selector = null, $context = null, $attr = 'src')
    {
        /**
         * @var \DOMElement $item
         */
        if ($selector) {
            foreach ($this->_query->xpath($selector, $context) as $item) {
                if ($item->nodeName === 'a') {
                    $item->setAttribute($attr, $this->absolutizeUrl($item->getAttribute($attr)));
                } else {
                    $this->absolutizeImgSrc(null, $item);
                }
            }
        } else {
            foreach ($this->_query->xpath("descendant:://a[not(starts-with(@$attr, 'http'))]", $context) as $item) {
                $item->setAttribute($attr, $this->absolutizeUrl($item->getAttribute($attr)));
            }
        }

        return $this;
    }
}