<?php

namespace ManaPHP;

use ManaPHP\Di\InstanceState;
use ManaPHP\Exception\BadMethodCallException;
use ManaPHP\Exception\InvalidValueException;
use ManaPHP\Exception\NotSupportedException;
use ManaPHP\Exception\PreconditionException;
use ManaPHP\Exception\RuntimeException;
use ManaPHP\Exception\UnexpectedValueException;

/**
 * Class ManaPHP\Di
 *
 * @package  di
 *
 * @property \ManaPHP\AliasInterface                   $alias
 * @property \ManaPHP\Mvc\DispatcherInterface          $dispatcher
 * @property \ManaPHP\RouterInterface                  $router
 * @property \ManaPHP\View\UrlInterface                $url
 * @property \ManaPHP\Http\RequestInterface            $request
 * @property \ManaPHP\Http\FilterInterface             $filter
 * @property \ManaPHP\Http\ResponseInterface           $response
 * @property \ManaPHP\Http\CookiesInterface            $cookies
 * @property \ManaPHP\View\FlashInterface              $flash
 * @property \ManaPHP\View\FlashInterface              $flashSession
 * @property \ManaPHP\Http\SessionInterface            $session
 * @property \ManaPHP\Event\ManagerInterface           $eventsManager
 * @property \ManaPHP\DbInterface                      $db
 * @property \ManaPHP\Security\CryptInterface          $crypt
 * @property \ManaPHP\Db\Model\MetadataInterface       $modelsMetadata
 * @property \ManaPHP\Cache\EngineInterface            $modelsCache
 * @property \ManaPHP\Model\ValidatorInterface         $modelsValidator
 * @property \ManaPHP\Di|\ManaPHP\DiInterface          $di
 * @property \ManaPHP\ViewInterface                    $view
 * @property \ManaPHP\Loader                           $loader
 * @property \ManaPHP\LoggerInterface                  $logger
 * @property \ManaPHP\RendererInterface                $renderer
 * @property \ManaPHP\Configuration\Configure          $configure
 * @property \ManaPHP\Configuration\SettingsInterface  $settings
 * @property \ManaPHP\ApplicationInterface             $application
 * @property \ManaPHP\DebuggerInterface                $debugger
 * @property \ManaPHP\Authentication\PasswordInterface $password
 * @property \Redis                                    $redis
 * @property \ManaPHP\Serializer\AdapterInterface      $serializer
 * @property \ManaPHP\CacheInterface                   $cache
 * @property \ManaPHP\CounterInterface                 $counter
 * @property \ManaPHP\Cache\EngineInterface            $viewsCache
 * @property \ManaPHP\Curl\EasyInterface               $httpClient
 * @property \ManaPHP\AuthorizationInterface           $authorization
 * @property \ManaPHP\Security\CaptchaInterface        $captcha
 * @property \ManaPHP\Security\CsrfTokenInterface      $csrfToken
 * @property \ManaPHP\IdentityInterface                $identity
 * @property \ManaPHP\Paginator                        $paginator
 * @property \ManaPHP\FilesystemInterface              $filesystem
 * @property \ManaPHP\Security\RandomInterface         $random
 * @property \ManaPHP\Message\QueueInterface           $messageQueue
 * @property \ManaPHP\Text\CrosswordInterface          $crossword
 * @property \ManaPHP\Security\RateLimiterInterface    $rateLimiter
 * @property \ManaPHP\Meter\LinearInterface            $linearMeter
 * @property \ManaPHP\Meter\RoundInterface             $roundMeter
 * @property \ManaPHP\Security\SecintInterface         $secint
 * @property \ManaPHP\I18n\Translation                 $translation
 * @property \ManaPHP\Renderer\Engine\Sword\Compiler   $swordCompiler
 * @property \ManaPHP\StopwatchInterface               $stopwatch
 * @property \ManaPHP\Security\HtmlPurifierInterface   $htmlPurifier
 * @property \ManaPHP\Net\ConnectivityInterface        $netConnectivity
 * @property \ManaPHP\AmqpInterface                    $rabbitmq
 * @property \ManaPHP\Model\Relation\Manager           $relationsManager
 * @property \ManaPHP\MailerInterface                  $mailer
 * @property \ManaPHP\Task\ManagerInterface            $tasksManager
 */
class Di implements DiInterface
{
    /**
     * @var array
     */
    protected $_components = [];

    /**
     * @var array
     */
    protected $_aliases = [];

    /**
     * @var array
     */
    protected $_instances = [];

    /**
     * @var bool
     */
    protected $_keepInstanceState = false;

    /**
     * @var \ManaPHP\Di\InstanceState[]
     */
    protected $_instancesState = [];

    /**
     * @var array
     */
    protected $_traces;

    /**
     * First DI build
     *
     * @var \ManaPHP\Di
     */
    protected static $_default;

    public function __construct()
    {
        if (self::$_default === null) {
            self::$_default = $this;
        }

        if (error_reporting() === E_ALL) {
            $this->_traces = ['*'];
        }
    }

    /**
     * Return the First DI created
     *
     * @return static
     */
    public static function getDefault()
    {
        return self::$_default;
    }

    /**
     * @param string $name
     * @param string $className
     *
     * @return string
     */
    protected function _completeClassName($name, $className)
    {
        if (isset($this->_components[$name])) {
            $definition = $this->_components[$name];
        } elseif (isset($this->_aliases[$name])) {
            $definition = $this->_components[$this->_aliases[$name]];
        } else {
            return $className;
        }

        if (is_string($definition)) {
            if ($pos = strrpos($definition, '\\')) {
                return substr($definition, 0, $pos + 1) . ucfirst($className);
            } else {
                return $className;
            }
        } elseif (is_array($definition) && isset($definition['class'])) {
            if ($pos = strrpos($definition['class'], '\\')) {
                return substr($definition['class'], 0, $pos + 1) . ucfirst($className);
            } else {
                return $className;
            }
        } else {
            return $className;
        }
    }

    /**
     * @param string $name
     *
     * @return string
     */
    protected function _interClassName($name)
    {
        $component = null;
        if (isset($this->_components[$name])) {
            $component = $this->_components[$name];
        } elseif (isset($this->_aliases[$name])) {
            $component = $this->_components[$this->_aliases[$name]];
        } elseif (strpos($name, '\\') !== false) {
            $component = $name;
        } elseif ($pos = strrpos($name, '_')) {
            $maybe = substr($name, $pos + 1);
            if (isset($this->_components[$maybe])) {
                $component = $this->_components[$maybe];
            }
        } elseif (preg_match('#^(.+)([A-Z].+?)$#', $name, $match)) {
            $maybe = lcfirst($match[2]);
            if (isset($this->_components[$maybe])) {
                $component = $this->_components[$maybe];
            }
        }

        if ($component === null) {
            throw new InvalidValueException(['`:component` component definition is invalid: missing class field', 'component' => $name]);
        }

        return is_string($component) ? $component : $component['class'];
    }

    /**
     * Registers a component in the components container
     *
     * @param string $name
     * @param mixed  $definition
     *
     * @return static
     */
    public function set($name, $definition)
    {
        if (is_string($definition)) {
            if (strpos($definition, '/') !== false || preg_match('#^[\w\\\\]+$#', $definition) !== 1) {
                $definition = ['class' => $this->_interClassName($name), $definition, 'shared' => false];
            } else {
                if (strpos($definition, '\\') === false) {
                    $definition = $this->_completeClassName($name, $definition);
                }
                $definition = ['class' => $definition, 'shared' => false];
            }
        } elseif (is_array($definition)) {
            if (isset($definition['class'])) {
                if (strpos($definition['class'], '\\') === false) {
                    $definition['class'] = $this->_completeClassName($name, $definition['class']);
                }
            } elseif (isset($definition[0]) && count($definition) !== 1) {
                if (strpos($definition[0], '\\') === false) {
                    $definition[0] = $this->_completeClassName($name, $definition[0]);
                }
            } else {
                $definition['class'] = $this->_interClassName($name);
            }

            $definition['shared'] = false;
        } elseif (is_object($definition)) {
            $definition = ['class' => $definition, 'shared' => !$definition instanceof \Closure];
        } else {
            throw new UnexpectedValueException(['`:component` component definition is unknown', 'component' => $name]);
        }

        $this->_components[$name] = $definition;

        return $this;
    }

    /**
     * Registers an "always shared" component in the components container
     *
     * @param string $name
     * @param mixed  $definition
     *
     * @return static
     */
    public function setShared($name, $definition)
    {
        if (isset($this->_instances[$name])) {
            throw new RuntimeException(['it\'s too late to setShared(): `:name` instance has been created', 'name' => $name]);
        }

        if (is_string($definition)) {
            if (strpos($definition, '/') !== false || preg_match('#^[\w\\\\]+$#', $definition) !== 1) {
                $definition = ['class' => $this->_interClassName($name), $definition];
            } elseif (strpos($definition, '\\') === false) {
                $definition = $this->_completeClassName($name, $definition);
            }
        } elseif (is_array($definition)) {
            if (isset($definition['class'])) {
                if (strpos($definition['class'], '\\') === false) {
                    $definition['class'] = $this->_completeClassName($name, $definition['class']);
                }
            } elseif (isset($definition[0]) && count($definition) !== 1) {
                if (strpos($definition[0], '\\') === false) {
                    $definition[0] = $this->_completeClassName($name, $definition[0]);
                }
            } else {
                $definition['class'] = $this->_interClassName($name);
            }
        } elseif (is_object($definition)) {
            $definition = ['class' => $definition];
        } else {
            throw new UnexpectedValueException(['`:component` component definition is unknown', 'component' => $name]);
        }

        $this->_components[$name] = $definition;

        return $this;
    }

    /**
     * @param string       $component
     * @param string|array $aliases
     * @param bool         $force
     *
     * @return static
     */
    public function setAliases($component, $aliases, $force = false)
    {
        if (is_string($aliases)) {
            if ($force || !isset($this->_aliases[$aliases])) {
                $this->_aliases[$aliases] = $component;
            }
        } else {
            /** @noinspection ForeachSourceInspection */
            foreach ($aliases as $alias) {
                if ($force || !isset($this->_aliases[$alias])) {
                    $this->_aliases[$alias] = $component;
                }
            }
        }

        return $this;
    }

    /**
     * @param array $names
     *
     * @return static
     */
    public function setTraces($names)
    {
        if (in_array('*', $names, true)) {
            $this->_traces = ['*'];
        } else {
            $traces = [];
            foreach ($names as $name) {
                if (strpos($name, '*') !== false) {
                    foreach ($this->_components as $component => $definition) {
                        if (fnmatch($name, $component)) {
                            $traces[] = $component;
                        }
                    }
                } else {
                    $traces[] = $name;
                }
            }

            foreach ($this->_instances as $name => $instance) {
                if ($instance instanceof Component) {
                    $instance->enableTrace($traces === ['*'] || in_array($name, $traces, true));
                }
            }

            $this->_traces = $traces;
        }

        return $this;
    }

    /**
     * Removes a component in the components container
     *
     * @param string $name
     *
     * @return static
     */
    public function remove($name)
    {
        if (in_array($name, $this->_aliases, true)) {
            throw new PreconditionException(['`:name` component is being used by alias, please remove alias first', 'name' => $name]);
        }

        if (isset($this->_aliases[$name])) {
            unset($this->_aliases[$name]);
        } else {
            unset($this->_components[$name], $this->_instances[$name], $this->{$name});
        }

        return $this;
    }

    /**
     * @param mixed  $definition
     * @param array  $parameters
     * @param string $name
     *
     * @return mixed
     */
    public function getInstance($definition, $parameters = null, $name = null)
    {
        if (is_string($definition)) {
            $params = [];
        } elseif (isset($definition['class'])) {
            $params = $definition;
            $definition = $definition['class'];
            unset($params['class'], $params['shared']);
        } elseif (isset($definition[0])) {
            $params = $definition;
            $definition = $definition[0];
            unset($params[0], $params['shared']);
        } else {
            $params = [];
        }

        if ($parameters === null) {
            if (!$params || isset($params[0])) {
                $parameters = $params;
            } else {
                $parameters = [$params];
            }
        } else {
            if (count($parameters) !== 0 && !isset($parameters[0])) {
                $parameters = [$parameters];
            }
        }

        if (is_string($definition)) {
            if ($definition[0] === '@') {
                $definition = $this->alias->resolveNS($definition);
            }
			
            if (!class_exists($definition)) {
                throw new InvalidValueException(['`:name` component cannot be resolved: `:class` class is not exists', 'name' => $name, 'class' => $definition]);
            }
            $count = count($parameters);

            if ($count === 0) {
                $instance = new $definition();
            } elseif ($count === 1) {
                $instance = new $definition($parameters[0]);
            } elseif ($count === 2) {
                $instance = new $definition($parameters[0], $parameters[1]);
            } elseif ($count === 3) {
                $instance = new $definition($parameters[0], $parameters[1], $parameters[2]);
            } else {
                /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
                /** @noinspection PhpUnhandledExceptionInspection */
                $reflection = new \ReflectionClass($definition);
                $instance = $reflection->newInstanceArgs($parameters);
            }
        } elseif ($definition instanceof \Closure) {
            $instance = call_user_func_array($definition, $parameters);
        } elseif (is_object($definition)) {
            $instance = $definition;
        } else {
            throw new NotSupportedException(['`:name` component cannot be resolved: component implement type is not supported', 'name' => $name]);
        }

        if ($instance instanceof Component) {
            if ($name !== null && $this->_traces !== null) {
                if ($this->_traces === ['*'] || in_array($name, $this->_traces, true)) {
                    $instance->enableTrace();
                }
            }

            $instance->setDi($this);
            if ($this->_keepInstanceState && ($state = $instance->saveInstanceState()) !== false) {
                $this->_instancesState[] = new InstanceState($name, $instance, $state);
            }
        }

        return $instance;
    }

    /**
     * Resolves the component based on its configuration
     *
     * @param string $name
     * @param array  $parameters
     *
     * @return mixed
     */
    public function get($name, $parameters = null)
    {
        if (isset($this->_instances[$name])) {
            return $this->_instances[$name];
        }

        if (isset($this->_aliases[$name], $this->_instances[$this->_aliases[$name]])) {
            return $this->_instances[$this->_aliases[$name]];
        }

        if (isset($this->_components[$name])) {
            $definition = $this->_components[$name];
        } elseif (isset($this->_aliases[$name])) {
            $definition = $this->_components[$this->_aliases[$name]];
        } else {
            return $this->getInstance($name, $parameters, $name);
        }

        $instance = $this->getInstance($definition, $parameters, $name);

        if (is_string($definition) || !isset($definition['shared']) || $definition['shared'] === true) {
            if (isset($this->_components[$name])) {
                $this->_instances[$name] = $instance;
            } else {
                $this->_instances[$this->_aliases[$name]] = $instance;
            }
        }

        return $instance;
    }

    /**
     * Resolves a component, the resolved component is stored in the DI, subsequent requests for this component will return the same instance
     *
     * @param string $name
     *
     * @return mixed
     */
    public function getShared($name)
    {
        if (isset($this->_instances[$name])) {
            return $this->_instances[$name];
        }

        if (isset($this->_aliases[$name], $this->_instances[$this->_aliases[$name]])) {
            return $this->_instances[$this->_aliases[$name]];
        }

        if (isset($this->_components[$name])) {
            return $this->_instances[$name] = $this->getInstance($this->_components[$name], null, $name);
        } elseif (isset($this->_aliases[$name])) {
            return $this->_instances[$this->_aliases[$name]] = $this->getInstance($this->_components[$this->_aliases[$name]], null, $name);
        } else {
            return $this->_instances[$name] = $this->getInstance($name, null, $name);
        }
    }

    /**
     * @param string $name
     *
     * @return string|array|callable|null
     */
    public function getDefinition($name)
    {
        return isset($this->_components[$name]) ? $this->_components[$name] : null;
    }

    /**
     * Magic method __get
     *
     * @param string $propertyName
     *
     * @return mixed
     */
    public function __get($propertyName)
    {
        return $this->getShared($propertyName);
    }

    /**
     * @param string $name
     * @param mixed  $value
     */
    public function __set($name, $value)
    {
        if ($value === null) {
            $this->remove($name);
        } else {
            $this->setShared($name, $value);
        }
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function __isset($name)
    {
        return $this->has($name);
    }

    /**
     * Check whether the DI contains a component by a name
     *
     * @param string $name
     *
     * @return bool
     */
    public function has($name)
    {
        return isset($this->_components[$name]) || isset($this->_aliases[$name]);
    }

    /**
     * Magic method to get or set components using setters/getters
     *
     * @param string $method
     * @param array  $arguments
     *
     * @return void
     */
    public function __call($method, $arguments = [])
    {
        throw new BadMethodCallException(['Call to undefined method `:method`', 'method' => $method]);
    }

    /**
     * @return array
     */
    public function __debugInfo()
    {
        return get_object_vars($this);
    }

    /**
     * @param bool $keep
     *
     * @return void
     */
    public function keepInstanceState($keep = true)
    {
        $this->_keepInstanceState = $keep;
    }

    public function restoreInstancesState()
    {
        foreach ($this->_instancesState as $item) {
            $item->instance->restoreInstanceState($item->state);
        }
    }
}
