<?php
namespace ManaPHP\View;

use ManaPHP\Component;
use ManaPHP\Utility\Text;

/**
 * Class ManaPHP\View\Url
 *
 * @package url
 *
 * @property \ManaPHP\Configuration\Configure $configure
 * @property \ManaPHP\Http\RequestInterface   $request
 * @property \ManaPHP\RouterInterface         $router
 * @property \ManaPHP\Mvc\DispatcherInterface $dispatcher
 */
class Url extends Component implements UrlInterface
{
    /**
     * @var string
     */
    protected $_assets;

    /**
     * @var string
     */
    protected $_prefix;

    /**
     * Url constructor.
     *
     * @param array $options
     */
    public function __construct($options = [])
    {
        $selfPath = strtr(dirname($_SERVER['PHP_SELF']), '\\', '/');
        if ($selfPath !== '/' && preg_match('#(.*)/public$#', $selfPath, $match) === 1) {
            $selfPath = $match[1];
        } else {
            $selfPath = rtrim($selfPath, '/');
        }

        $this->_prefix = $this->router->getPrefix();
        if ($this->_prefix[0] === '/') {
            $this->_prefix = $selfPath . $this->_prefix;
        }

        $this->_assets = $selfPath . (isset($options['assets']) ? rtrim($options['assets'], '/') : '');
    }

    /**
     * @param string|array $args
     *
     * @return string
     */
    public function get($args = [])
    {
        /** @noinspection CallableParameterUseCaseInTypeContextInspection */
        if (is_string($args)) {
            $uri = $args;
            $args = [];
        } else {
            $uri = $args[0];
            unset($args[0]);

            if (isset($args['#'])) {
                $anchor = $args['#'];
                unset($args['#']);
            }
        }

        if ($uri === '' || $uri[0] !== '/') {
            $strUrl = (strpos($this->_prefix, '://') ? parse_url($this->_prefix, PHP_URL_PATH) : $this->_prefix) . '/' . $uri;
        } else {
            $strUrl = ($this->_prefix === '/' ? '' : rtrim($this->_prefix, '/')) . $uri;
        }

        if (Text::contains($strUrl, ':')) {
            /** @noinspection ForeachSourceInspection */
            foreach ($args as $k => $v) {
                $count = 0;
                $strUrl = str_replace(':' . $k, $v, $strUrl, $count);
                if ($count !== 0) {
                    unset($args[$k]);
                }
            }
        }

        if (count($args) !== 0) {
            $strUrl .= (Text::contains($strUrl, '?') ? '&' : '?') . http_build_query($args);
        }

        if (isset($anchor)) {
            $strUrl .= '#' . $anchor;
        }

        return $strUrl;
    }

    /**
     * @param string $uri
     *
     * @return string
     */
    public function getAsset($uri)
    {
        if ($uri[0] !== '/') {
            $uri = '/' . $uri;
        }

        return $this->_assets . $uri;
    }
}