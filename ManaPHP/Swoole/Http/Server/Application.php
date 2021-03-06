<?php
namespace ManaPHP\Swoole\Http\Server;

use ManaPHP\Http\Response;
use ManaPHP\Rest\Factory;
use ManaPHP\Router\NotFoundRouteException;

/**
 * Class ManaPHP\Mvc\Application
 *
 * @package application
 * @property \ManaPHP\Http\RequestInterface       $request
 * @property \ManaPHP\Http\ResponseInterface      $response
 * @property \ManaPHP\RouterInterface             $router
 * @property \ManaPHP\Mvc\DispatcherInterface     $dispatcher
 * @property \ManaPHP\Http\SessionInterface       $session
 * @property \ManaPHP\Swoole\Http\ServerInterface $swooleHttpServer
 */
class Application extends \ManaPHP\Application
{
    /**
     * Application constructor.
     *
     * @param \ManaPHP\Loader $loader
     */
    public function __construct($loader = null)
    {
        ini_set('html_errors', 'off');
        parent::__construct($loader);
        $routerClass = $this->alias->resolveNS('@ns.app\Router');
        if (class_exists($routerClass)) {
            $this->_di->setShared('router', $routerClass);
        }
    }

    public function getDi()
    {
        if (!$this->_di) {
            $this->_di = new Factory();
            $this->_di->setShared('response', '\ManaPHP\Swoole\Http\Server\Response');
            $this->_di->setShared('swooleHttpServer', 'ManaPHP\Swoole\Http\Server');
            $this->_di->keepInstanceState(true);
        }

        return $this->_di;
    }

    public function authenticate()
    {

    }

    public function handle()
    {
        try {
            $this->authenticate();

            if (!$this->router->handle()) {
                throw new NotFoundRouteException(['router does not have matched route for `:uri`', 'uri' => $this->router->getRewriteUri()]);
            }

            $controllerName = $this->router->getControllerName();
            $actionName = $this->router->getActionName();
            $params = $this->router->getParams();

            $ret = $this->dispatcher->dispatch($controllerName, $actionName, $params);
            if ($ret !== false) {
                $actionReturnValue = $this->dispatcher->getReturnedValue();
                if ($actionReturnValue instanceof Response) {
                    null;
                } else {
                    $this->response->setJsonContent($actionReturnValue);
                }
            }
        } catch (\Exception $exception) {
            $this->handleException($exception);
        } catch (\Error $error) {
            $this->handleException($error);
        }

        $this->response->send();
        $this->_di->restoreInstancesState();
    }

    public function main()
    {
        $this->dotenv->load();
        $this->configure->load();

        $this->registerServices();

        $this->swooleHttpServer->start([$this, 'handle']);
    }
}