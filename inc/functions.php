<?php
/**
 * This file contain API function that can be used to run packages tasks without dealing with
 * internal objects, container and so on.
 *
 * After Engine has been created, via the `Foil\engine()` function, all the other functions
 * in this file can be also accessed via API class.
 * E.g. is possible to do
 *
 * `$api = new Foil\API();`
 * `$api->fire($event);`
 *
 * This allows to easily integrate functions in OOP projects (and mock it in tests).
 *
 * Functions in this file are snake_cased, but when using API is possible to call them using
 * camelCase, e.g. the function `Foil\add_global_context($data)` can be called using
 * `$api->addGlobalContext($data)`.
 *
 * @author Giuseppe Mazzapica <giuseppe.mazzapica@gmail.com>
 * @package foil\foil
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace Foil;

use Foil\Context\SearchContext;
use Foil\Context\RegexContext;
use Foil\Context\GlobalContext;
use Foil\Kernel\Arraize;
use LogicException;
use InvalidArgumentException;

if (! function_exists('Foil\foil')) {
    /**
     * On first call instantiate the container (Pimple) and register service providers.
     * On subsequent calls returns container or a service whose id has been passed as argument.
     *
     * @staticvar \Pimple\Container     $container
     * @param  string|void              $which            Service id
     * @param  array                    $options          Engine options
     * @param  array                    $custom_providers Custom service provider classes
     * @return mixed                    The container or the service whose id has been passed in $which
     * @throws LogicException           If used to read service before engine has been set
     * @throws InvalidArgumentException If service id is not a string or service is not registered
     */
    function foil($which = null, array $options = [], array $custom_providers = [])
    {
        static $container = null;
        if (is_null($container) && $which !== 'engine') {
            throw new LogicException('Engine must be instantiated before to retrieve any service.');
        } elseif (is_null($container)) {
            $bootstrapper = new Bootstrapper();
            $providers = [
                'kernel'     => '\\Foil\\Providers\\Kernel',
                'core'       => '\\Foil\\Providers\\Core',
                'context'    => '\\Foil\\Providers\\Context',
                'extensions' => '\\Foil\\Providers\\Extensions',
            ];
            if (! empty($custom_providers)) {
                $providers = array_merge($providers, array_filter($custom_providers, 'is_string'));
            }
            $container = $bootstrapper->init($options, array_values($providers));
            $container['api'] = new API();
            $bootstrapper->boot($container);
        } elseif (! is_null($which) && ! is_string($which)) {
            throw new InvalidArgumentException('Service name must be in a string.');
        }

        return is_null($which) ? $container : $container[$which];
    }
}

if (! function_exists('Foil\engine')) {
    /**
     * This function is the preferred way to be used to create a Foil engine.
     *
     * @param  array        $options Options: autoescape, default and allowed extensions, folders...
     * @return \Foil\Engine
     */
    function engine(array $options = [])
    {
        return foil('engine', $options);
    }
}

if (! function_exists('Foil\template')) {
    /**
     *
     * @param  string                  $path
     * @param  string|void             $class
     * @return \Foil\Template\Template
     */
    function template($path, $class = null)
    {
        return $this->container['template.factory']->factory($path, $class);
    }
}

if (! function_exists('Foil\render_template')) {
    /**
     * Render a template using a full template file path and some data.
     * When used before any engine() call, is possible to set engine options.
     *
     * @param  string $path    Full path for the template
     * @param  array  $data    Template contex
     * @param  array  $options Options for the engine
     * @return string
     */
    function render_template($path, array $data = [], array $options = [])
    {
        return engine($options)->renderTemplate($path, $data);
    }
}

if (! function_exists('Foil\option')) {
    /**
     * Return options array or optionally a specific option whose name is passed in $which param
     *
     * @param  string                   $which
     * @return mixed
     * @throws InvalidArgumentException When $which param isn't a string nor a valid option name
     */
    function option($which = null)
    {
        if (! is_null($which) && ! is_string($which)) {
            throw new InvalidArgumentException('Option name must be in a string.');
        }
        $options = foil('options');

        return is_null($which) ? $options : $options[$which];
    }
}

if (! function_exists('Foil\add_context')) {
    /**
     * Add some data for specific templates based on a search or on a regex match.
     *
     * @param array   $data     Data to set for the templates
     * @param string  $needle   String to compare template name to
     * @param boolean $is_regex If true template name will be compared using $needle as a regex
     */
    function add_context(array $data, $needle, $is_regex = false)
    {
        $context = empty($is_regex) ?
            new SearchContext($needle, $data) :
            new RegexContext($needle, $data);
        foil('context')->add($context);
    }
}

if (! function_exists('Foil\add_global_context')) {
    /**
     * Add data to all templates
     *
     * @param array $data
     */
    function add_global_context(array $data)
    {
        foil('context')->add(new GlobalContext($data));
    }
}

if (! function_exists('Foil\add_context_using')) {
    /**
     * Add a custom context class
     *
     * @param ContextInterface $context
     */
    function add_context_using(ContextInterface $context)
    {
        foil('context')->add($context);
    }
}

if (! function_exists('Foil\run')) {
    /**
     * Run a registered custom function
     *
     * @param  string $function Function name
     * @return mixed
     */
    function run($function)
    {
        if (! is_string($function)) {
            throw new InvalidArgumentException('Function name must be in a string.');
        }

        return call_user_func_array([foil('command'), 'run'], func_get_args());
    }
}

if (! function_exists('Foil\fire')) {
    /**
     * Fire an event using Foil event emitter
     *
     * @param string $event
     */
    function fire($event)
    {
        if (! is_string($event)) {
            throw new InvalidArgumentException('Event name must be in a string.');
        }
        call_user_func_array([ foil('events'), 'fire'], func_get_args());
    }
}

if (! function_exists('Foil\on')) {
    /**
     * Listen to an event using Foil event emitter
     *
     * @param string   $event
     * @param callable $callback
     */
    function on($event, callable $callback, $once = false)
    {
        if (! is_string($event)) {
            throw new InvalidArgumentException('Event name must be in a string.');
        }
        $cb = empty($once) ? 'on' : 'once';
        foil('events')->$cb($event, $callback);
    }
}

if (! function_exists('Foil\entities')) {
    /**
     * Escape strings and array for htmlentities
     *
     * @param  mixed $data
     * @return mixed
     */
    function entities($data)
    {
        if (is_string($data)) {
            return htmlentities($data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
        } elseif (is_array($data)) {
            foreach ($data as $i => $val) {
                $data[$i] = entities($val);
            }
        } elseif ($data instanceof Traversable) {
            $convert = [];
            $n = 0;
            foreach ($data as $i => $val) {
                $n ++;
                $key = is_string($i) ? $i : $n;
                $convert[$key] = entities($val);
            }
            $data = $convert;
        }

        return $data;
    }
}

if (! function_exists('Foil\decode')) {
    /**
     * Decode strings and array from htmlentities
     *
     * @param  mixed $data
     * @return mixed
     */
    function decode($data)
    {
        if (is_string($data)) {
            return utf8_decode(html_entity_decode($data, ENT_QUOTES, 'UTF-8'));
        } elseif (is_array($data)) {
            foreach ($data as $i => $val) {
                $data[$i] = decode($val);
            }
        } elseif ($data instanceof Traversable) {
            $convert = [];
            $n = 0;
            foreach ($data as $i => $val) {
                $n ++;
                $key = is_string($i) ? $i : $n;
                $convert[$key] = decode($val);
            }
            $data = $convert;
        }

        return $data;
    }
}

if (! function_exists('Foil\arrayze')) {
    /**
     * Stateless class that recursively convert an array or a traversable object into a nested array.
     * Optionally convert all "atomic" items to strings and optionally HTML-encode all strings.
     * Nested array and traversable objects are all converted recursively.
     * Non-traversable objects are converted to array, in 1st available among following 5 methods:
     *  - if a transformer class is provided, than transformer transform() method is called
     *  - if the object has a method toArray() it is called
     *  - if the object has a method asArray() it is called
     *  - if the is an instance of JsonSerializable it is JSON-encoded then decoded
     *  - calling get_object_vars()
     *
     * @param  mixed $data        Data to convert
     * @param  bool  $escape      Should strings in data be HTML-encoded?
     * @param  array $trasformers Transformers: full qualified class names, objects or callables
     * @param  bool  $tostring    Should all scalar items in data be casted to strings?
     * @return array
     */
    function arraize($data = [], $escape = false, array $trasformers = [], $tostring = false)
    {
        return (new Arraize())->run($data, $escape, $trasformers, $tostring);
    }
}