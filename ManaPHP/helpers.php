<?php

use ManaPHP\Di;

if (PHP_VERSION_ID < 70000) {
    require_once __DIR__ . '/polyfill.php';
}

if (!function_exists('di')) {
    /**
     * @param string $name
     * @param array  $params
     *
     * @return mixed
     */
    function di($name = null, $params = null)
    {
        static $di;
        if (!$di) {
            $di = Di::getDefault();
        }

        if ($name === null || $name === 'di') {
            return $di;
        } elseif ($params) {
            return $di->getInstance($name, $params);
        } else {
            return $di->getShared($name);
        }
    }
}

if (!function_exists('env')) {
    /**
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    function env($key = null, $default = null)
    {
        return di('dotenv')->getEnv($key, $default);
    }
}

if (!function_exists('config')) {
    /**
     * @param string $key
     *
     * @return mixed|array
     */
    function config($key)
    {
        static $config = [];

        if (!isset($config[$key])) {
            $config[$key] = di('configure')->$key;
        }

        return $config[$key];
    }
}

if (!function_exists('settings')) {
    /**
     * @param string    $key
     * @param int|array $maxDelay
     *
     * @return array
     */
    function settings($key, $maxDelay = null)
    {
        if (is_array($maxDelay)) {
            di('settings')->set($key, $maxDelay);
            return $maxDelay;
        } else {
            return di('settings')->get($key, $maxDelay);
        }
    }
}

if (!function_exists('debug')) {
    /**
     * @param string|array $message
     * @param string       $category
     *
     * @return \ManaPHP\LoggerInterface
     */
    function debug($message, $category = null)
    {
        return di('logger')->debug($message, $category);
    }
}

if (!function_exists('info')) {
    /**
     * @param string|array $message
     * @param string       $category
     *
     * @return \ManaPHP\LoggerInterface
     */
    function info($message, $category = null)
    {
        return di('logger')->info($message, $category);
    }
}

if (!function_exists('warn')) {
    /**
     * @param string|array $message
     * @param string       $category
     *
     * @return \ManaPHP\LoggerInterface
     */
    function warn($message, $category = null)
    {
        return di('logger')->warn($message, $category);
    }
}

if (!function_exists('error')) {
    /**
     * @param string|array $message
     * @param string       $category
     *
     * @return \ManaPHP\LoggerInterface
     */
    function error($message, $category = null)
    {
        return di('logger')->error($message, $category);
    }
}

if (!function_exists('fatal')) {
    /**
     * @param string|array $message
     * @param string       $category
     *
     * @return \ManaPHP\LoggerInterface
     */
    function fatal($message, $category = null)
    {
        return di('logger')->fatal($message, $category);
    }
}

if (!function_exists('cache')) {
    /**
     * @param string                $name
     * @param false|\Closure|string $default
     * @param int                   $ttl
     *
     * @return bool|array|mixed|\ManaPHP\CacheInterface
     */
    function cache($name = null, $default = false, $ttl = null)
    {
        if ($ttl) {
            if ($default instanceof \Closure) {
                $value = di('cache')->get($name);
                if ($value !== false) {
                    return $value;
                } else {
                    di('cache')->set($name, $default(), $ttl);
                }
            } else {
                di('cache')->set($name, $default, $ttl);
            }
            return null;
        } else {
            if (!$name) {
                return di('cache');
            } elseif (strpos($name, ':') === false) {
                return di("${name}Cache");
            } else {
                $value = di('cache')->get($name);
                return $value === false ? $value : $default;
            }
        }
    }
}

if (!function_exists('path')) {
    /**
     * @param string $path
     *
     * @return string
     */
    function path($path)
    {
        return $path ? di('alias')->resolve($path) : di('alias')->get();
    }
}

if (!function_exists('abort')) {
    /**
     * @param int          $code
     * @param string|array $message
     */
    function abort($code, $message = null)
    {
        if ($message) {
            if (is_string($message)) {
                di('response')->setStatus($code, $code === 200 ? 'OK' : 'Abort')->setContent($message);
            } else {
                di('response')->setStatus($code, $code === 200 ? 'OK' : 'Abort')->setJsonContent($message);
            }
            throw new \ManaPHP\ExitException('');
        }
    }
}

if (!function_exists('token')) {
    /**
     * @param string|array $data
     * @param int          $ttl
     *
     * @return array|false|string
     */
    function token($data = null, $ttl = null)
    {
        if ($ttl) {
            $data['exp'] = time() + $ttl;
            return di('authenticationToken')->encode($data);
        } else {
            return di('authenticationToken')->decode($data ?: di('request')->getAccessToken());
        }
    }
}

if (!function_exists('jwt')) {
    /**
     * @param string       $scope
     * @param string|array $data
     * @param int          $ttl
     *
     * @return string|array|false
     */
    function jwt($scope, $data, $ttl = null)
    {
        $jwt = di('ManaPHP\Authentication\Token\Adapter\Jwt', ['key' => di('crypt')->getDerivedKey("jwt:$scope")]);
        if ($ttl) {
            return $jwt->encode(array_merge(['scope' => $scope, 'exp' => time() + $ttl], $data));
        } else {
            $r = $jwt->decode($data);
            return !$r || !isset($r['scope']) || $r['scope'] !== $scope ? false : $r;
        }
    }
}

if (!function_exists('mwt')) {
    /**
     * @param string       $scope
     * @param string|array $data
     * @param int          $ttl
     *
     * @return string|array|false
     */
    function mwt($scope, $data, $ttl = null)
    {
        $mwt = di('ManaPHP\Authentication\Token\Adapter\Mwt', ['key' => di('crypt')->getDerivedKey("mwt:$scope")]);
        if ($ttl) {
            return $mwt->encode(array_merge(['scope' => $scope, 'exp' => time() + $ttl], $data));
        } else {
            $r = $mwt->decode($data);
            return !$r || !isset($r['scope']) || $r['scope'] !== $scope ? false : $r;
        }
    }
}

if (!function_exists('input')) {
    /**
     * @param string $name
     * @param mixed  $default
     *
     * @return string|array|\ManaPHP\Http\RequestInterface
     */
    function input($name = null, $default = null)
    {
        if ($name === 'id') {
            $params = di('dispatcher')->getParams();
            if (count($params) === 1 && isset($params[0])) {
                return $params[0];
            }
        }

        if (($value = di('request')->getInput($name, false, null)) === null) {
            if ($default === null) {
                $exception = new \ManaPHP\Exception\RequiredValidatorException($name);
                $exception->parameter_name = $name;
                throw $exception;
            } else {
                $value = $default;
            }
        }

        return $value;
    }
}

if (!function_exists('request')) {
    /**
     * @param string $name
     * @param mixed  $default
     *
     * @return string|array|\ManaPHP\Http\RequestInterface
     */
    function request($name = null, $default = null)
    {
        return $name === null ? di('request') : di('request')->get($name, false, $default);
    }
}

if (!function_exists('session')) {
    /**
     * @param string|array $data
     *
     * @return mixed|\ManaPHP\Http\SessionInterface
     */
    function session($data = null)
    {
        if ($data === null) {
            return di('session');
        } elseif (is_array($data)) {
            $session = di('session');
            foreach ((array)$data as $k => $v) {
                $session->set($k, $v);
            }
            return null;
        } else {
            return di('session')->get($data);
        }
    }
}

if (!function_exists('db')) {
    /**
     * @param string $name
     *
     * @return \ManaPHP\DbInterface
     */
    function db($name = null)
    {
        return di($name ? "${name}Db" : 'db');
    }
}

if (!function_exists('mongodb')) {
    /**
     * @param string $name
     *
     * @return \ManaPHP\MongodbInterface
     */
    function mongodb($name = null)
    {
        return di($name ? "${name}Mongodb" : 'mongodb');
    }
}

if (!function_exists('redis')) {
    /**
     * @param string $name
     *
     * @return \Redis
     */
    function redis($name = null)
    {
        return di($name ? "${name}Redis" : 'redis');
    }
}

if (!function_exists('client_ip')) {
    /**
     * @return string
     */
    function client_ip()
    {
        return di('request')->getClientIp();
    }
}

if (!function_exists('curl')) {
    /**
     * @param string       $type
     * @param string|array $url
     * @param string|array $body
     * @param array        $options
     *
     * @return \ManaPHP\Curl\Easy\Response
     * @throws \ManaPHP\Curl\ConnectionException
     */
    function curl($type, $url, $body = null, $options = [])
    {
        return di('httpClient')->request($type, $url, $body, $options);
    }
}

if (!function_exists('curl_get')) {
    /**
     * @param string|array $url
     * @param array        $options
     *
     * @return \ManaPHP\Curl\Easy\Response
     * @throws \ManaPHP\Curl\ConnectionException
     */
    function curl_get($url, $options = [])
    {
        return di('httpClient')->get($url, $options);
    }
}

if (!function_exists('curl_post')) {
    /**
     * @param string|array $url
     * @param string|array $body
     * @param array        $options
     *
     * @return \ManaPHP\Curl\Easy\Response
     * @throws \ManaPHP\Curl\ConnectionException
     */
    function curl_post($url, $body = null, $options = [])
    {
        return di('httpClient')->post($url, $body, $options);
    }
}

if (!function_exists('download')) {
    /**
     * @param string|array     $files
     * @param string|int|array $options
     *
     * @return string|array
     */
    function download($files, $options = [])
    {
        return di('httpClient')->download($files, $options);
    }
}

if (!function_exists('rest')) {
    /**
     * @param string       $type
     * @param string|array $url
     * @param string|array $body
     * @param array        $options
     *
     * @return array
     * @throws \ManaPHP\Curl\Easy\ServiceUnavailableException
     * @throws \ManaPHP\Curl\Easy\BadRequestException
     * @throws \ManaPHP\Curl\Easy\ContentTypeException
     * @throws \ManaPHP\Curl\Easy\JsonDecodeException
     * @throws \ManaPHP\Curl\ConnectionException
     */
    function rest($type, $url, $body = null, $options = [])
    {
        return di('httpClient')->rest($type, $url, $body, $options);
    }
}

if (!function_exists('rest_get')) {
    /**
     * @param string|array $url
     * @param array        $options
     *
     * @return array
     * @throws \ManaPHP\Curl\Easy\ServiceUnavailableException
     * @throws \ManaPHP\Curl\Easy\BadRequestException
     * @throws \ManaPHP\Curl\Easy\ContentTypeException
     * @throws \ManaPHP\Curl\Easy\JsonDecodeException
     * @throws \ManaPHP\Curl\ConnectionException
     */
    function rest_get($url, $options = [])
    {
        return di('httpClient')->rest('GET', $url, null, $options);
    }
}

if (!function_exists('rest_post')) {
    /**
     * @param string|array $url
     * @param string|array $body
     * @param array        $options
     *
     * @return array
     * @throws \ManaPHP\Curl\Easy\ServiceUnavailableException
     * @throws \ManaPHP\Curl\Easy\BadRequestException
     * @throws \ManaPHP\Curl\Easy\ContentTypeException
     * @throws \ManaPHP\Curl\Easy\JsonDecodeException
     * @throws \ManaPHP\Curl\ConnectionException
     */
    function rest_post($url, $body = null, $options = [])
    {
        return di('httpClient')->rest('POST', $url, $body, $options);
    }
}

if (!function_exists('rest_put')) {
    /**
     * @param string|array $url
     * @param string|array $body
     * @param array        $options
     *
     * @return array
     * @throws \ManaPHP\Curl\Easy\ServiceUnavailableException
     * @throws \ManaPHP\Curl\Easy\BadRequestException
     * @throws \ManaPHP\Curl\Easy\ContentTypeException
     * @throws \ManaPHP\Curl\Easy\JsonDecodeException
     * @throws \ManaPHP\Curl\ConnectionException
     */
    function rest_put($url, $body = null, $options = [])
    {
        return di('httpClient')->rest('PUT', $url, $body, $options);
    }
}

if (!function_exists('rest_delete')) {
    /**
     * @param string|array $url
     * @param array        $options
     *
     * @return array
     * @throws \ManaPHP\Curl\Easy\ServiceUnavailableException
     * @throws \ManaPHP\Curl\Easy\BadRequestException
     * @throws \ManaPHP\Curl\Easy\ContentTypeException
     * @throws \ManaPHP\Curl\Easy\JsonDecodeException
     * @throws \ManaPHP\Curl\ConnectionException
     */
    function rest_delete($url, $options = [])
    {
        return di('httpClient')->rest('DELETE', $url, null, $options);
    }
}

if (!function_exists('render')) {
    /**
     * @param string $file
     * @param array  $vars
     *
     * @return string
     */
    function render($file, $vars = [])
    {
        return di('renderer')->render($file, $vars);
    }
}

if (!function_exists('e')) {
    /**
     * @param string $value
     * @param bool   $doubleEncode
     *
     * @return string
     */
    function e($value, $doubleEncode = true)
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8', $doubleEncode);
    }
}

if (!function_exists('elapsed')) {
    /**
     * @param float|string $previous
     * @param int          $precision
     *
     * @return float
     */
    function elapsed($previous = null, $precision = 3)
    {
        static $stack;
        if (is_float($previous)) {
            return round(microtime(true) - $previous, 3);
        } elseif (is_string($previous)) {
            $key = $previous;
        } else {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1];
            $key = $backtrace['class'] . $backtrace['function'];
        }

        if (!isset($stack[$key]) || count($stack[$key]) % 2 === 0) {
            $stack[$key][] = microtime(true);
            return null;
        } else {
            $prev = array_pop($stack[$key]);
            return round(microtime(true) - $prev, $precision);
        }
    }
}

if (!function_exists('dd')) {
    function dd()
    {
        foreach (func_get_args() as $arg) {
            var_dump($arg);
        }
        exit(1);
    }
}

if (!function_exists('seconds')) {
    /**
     * @param string $str
     *
     * @return int
     */
    function seconds($str)
    {
        if (($r = strtotime($str, 0)) !== false) {
            return $r;
        } else {
            throw new \ManaPHP\Exception\InvalidValueException(['`:str` string is not a valid seconds expression', 'str' => $str]);
        }
    }
}

if (!function_exists('json')) {
    /**
     * @param array|string $data
     *
     * @return array|string
     */
    function json($data)
    {
        if (is_string($data)) {
            if (!is_array($r = json_decode($data, true))) {
                throw new \ManaPHP\Exception\InvalidJsonException(['`:data` data', 'data' => $data]);
            } else {
                return $r;
            }
        } elseif (is_array($data)) {
            return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            throw new \ManaPHP\Exception\UnexpectedValueException(['`:data`', 'data' => $data]);
        }
    }
}

if (!function_exists('transaction')) {
    /**
     * @param callable $work
     * @param string   $service
     *
     * @return true|string
     */
    function transaction($work, $service = 'db')
    {
        try {
            /**
             * @var \ManaPHP\DbInterface $db
             */
            $db = di($service);
            $db->begin();
            $work();
            $db->commit();
        } catch (\Exception $exception) {
            /** @noinspection UnSafeIsSetOverArrayInspection */
            isset($db) && $db->rollback();
            error($exception);
            return $exception->getMessage();
        } catch (\Error $error) {
            /** @noinspection UnSafeIsSetOverArrayInspection */
            isset($db) && $db->rollback();
            error($error);
            return $error->getMessage();
        }
        return true;
    }
}

if (!function_exists('size_to_str')) {
    /**
     * @param int $size
     * @param int $precision
     * @param int $base
     *
     * @return string
     */
    function size_to_str($size, $precision = 2, $base = 1024)
    {
        /** @noinspection PowerOperatorCanBeUsedInspection */
        return round($size / pow($base, $i = (int)round(log($size, $base))), $precision) . ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB'][$i];
    }
}

if (!function_exists('size_to_int')) {
    /**
     * @param string $size
     * @param int    $base
     *
     * @return int
     */
    function size_to_int($size, $base = 1024)
    {
        $size = rtrim(strtolower($size), 'b');
        if (is_numeric($size)) {
            return (int)$size;
        } else {
            /** @noinspection PowerOperatorCanBeUsedInspection */
            return (int)(substr($size, 0, -1) * pow($base, strpos('bkmgtpe', substr($size, -1))));
        }
    }
}

if (!function_exists('tap')) {
    /** @noinspection AutoloadingIssuesInspection */

    class _manaphp_tap_proxy
    {
        public $target;

        public function __construct($target)
        {
            $this->target = $target;
        }

        public function __call($method, $arguments)
        {
            call_user_func([$this->target, $method], $arguments);

            return $this->target;
        }
    }

    /**
     * @param mixed         $value
     * @param callable|null $callback
     *
     * @return mixed
     */
    function tap($value, $callback = null)
    {
        if ($callback === null) {
            return new _manaphp_tap_proxy($value);
        } else {
            $callback($value);
            return $value;
        }
    }
}

if (!function_exists('str_starts_with')) {
    /**
     * @param string $haystack
     * @param string $needle
     * @param int    $offset
     *
     * @return bool
     */
    function str_starts_with($haystack, $needle, $offset = 0)
    {
        return strpos($haystack, $needle, $offset) === 0;
    }
}

if (!function_exists('mbstr_starts_with')) {
    /**
     * @param string $haystack
     * @param string $needle
     * @param int    $offset
     *
     * @return bool
     */
    function mbstr_starts_with($haystack, $needle, $offset = 0)
    {
        return mb_strpos($haystack, $needle, $offset) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    /**
     * @param string $haystack
     * @param string $needle
     *
     * @return bool
     */
    function str_ends_with($haystack, $needle)
    {
        $lh = strlen($haystack);
        $ln = strlen($needle);
        if ($lh < $ln) {
            return false;
        }

        return substr_compare($haystack, $needle, $lh - $ln, $ln) === 0;
    }
}

if (!function_exists('mbstr_ends_with')) {
    /**
     * @param string $haystack
     * @param string $needle
     *
     * @return bool
     */
    function mbstr_ends_with($haystack, $needle)
    {
        $lh = mb_strlen($haystack);
        $ln = mb_strlen($needle);
        if ($lh < $ln) {
            return false;
        }

        return mb_substr($haystack, $lh - $ln) === $needle;
    }
}

if (!function_exists('str_contains')) {
    /**
     * @param string $haystack
     * @param string $needle
     *
     * @return bool
     */
    function str_contains($haystack, $needle)
    {
        return strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('mbstr_contains')) {
    /**
     * @param string $haystack
     * @param string $needle
     *
     * @return bool
     */
    function mbstr_contains($haystack, $needle)
    {
        return mb_strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_in_set')) {
    /**
     * @param string $needle
     * @param string $haystack
     *
     * @return bool
     */
    function str_in_set($needle, $haystack)
    {
        return preg_match('#\b' . preg_quote($needle, '#') . '\b#', $haystack) === 1;
    }
}

if (!function_exists('mbstr_in_set')) {
    /**
     * @param string $needle
     * @param string $haystack
     *
     * @return bool
     */
    function mbstr_in_set($needle, $haystack)
    {
        return preg_match('#\b' . preg_quote($needle, '#') . '\b#u', $haystack) === 1;
    }
}

if (!function_exists('str_fnmatch')) {
    /**
     * @param string       $needle
     * @param array|string $patterns
     *
     * @return bool
     */
    function str_fnmatch($needle, $patterns)
    {
        foreach ((array)(is_array($patterns) ? $patterns : preg_split('#[\s,]+#', $patterns, -1, PREG_SPLIT_NO_EMPTY)) as $pattern) {
            if (fnmatch($pattern, $needle)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('mbstr_fnmatch')) {
    /**
     * @param string       $needle
     * @param array|string $patterns
     *
     * @return bool
     */
    function mbstr_fnmatch($needle, $patterns)
    {
        foreach ((array)(is_array($patterns) ? $patterns : preg_split('#[\s,]+#/u', $patterns, -1, PREG_SPLIT_NO_EMPTY)) as $pattern) {
            if (fnmatch($pattern, $needle)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('array_field')) {
    /**
     * @param array  $input
     * @param string $field_key
     *
     * @return array
     */
    function array_field($input, $field_key)
    {
        $values = [];
        foreach ($input as $item) {
            $values[] = is_array($item) ? $item[$field_key] : $item->$field_key;
        }

        return $values;
    }
}

if (!function_exists('array_unique_field')) {
    /**
     * @param array  $input
     * @param string $field_key
     * @param int    $sort
     *
     * @return array
     */
    function array_unique_field($input, $field_key, $sort = SORT_REGULAR)
    {
        $values = [];
        foreach ($input as $item) {
            $value = is_array($item) ? $item[$field_key] : $item->$field_key;
            if (!in_array($value, $values, true)) {
                $values[] = $value;
            }
        }

        if ($sort !== null) {
            sort($values, $sort);
        }

        return $values;
    }
}

if (!function_exists('array_only')) {
    /**
     * @param array $ar
     * @param array $keys
     *
     * @return array
     */
    function array_only($ar, $keys)
    {
        return array_intersect_key($ar, array_fill_keys($keys, null));
    }
}

if (!function_exists('array_except')) {
    /**
     * @param array $ar
     * @param array $keys
     *
     * @return array
     */
    function array_except($ar, $keys)
    {
        return array_diff_key($ar, array_fill_keys($keys, null));
    }
}

if (!function_exists('array_dot')) {
    /**
     * @param array  $ar
     * @param string $prepend
     *
     * @return array
     */
    function array_dot($ar, $prepend = '')
    {
        $r = [];

        foreach ($ar as $key => $value) {
            if (is_array($value) && $value) {
                /** @noinspection SlowArrayOperationsInLoopInspection */
                $r = array_merge($r, array_dot($value, $prepend . $key . '.'));
            } else {
                $r[$prepend . $key] = $value;
            }
        }
        return $r;
    }
}

if (!function_exists('array_get')) {
    /**
     * @param array  $ar
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    function array_get($ar, $key, $default = null)
    {
        if (!$key) {
            return $ar;
        }

        if (($pos = strrpos($key, '.')) === false) {
            return isset($ar[$key]) ? $ar[$key] : null;
        }

        $t = $ar;
        foreach (explode('.', substr($key, 0, $pos)) as $segment) {
            if (!isset($t[$segment]) || !is_array($t[$segment])) {
                return $default;
            }
            $t = $t[$segment];
        }

        $last = substr($key, $pos + 1);
        return isset($t[$last]) ? $t[$last] : $default;
    }
}