<?php
namespace Thunder\Shortcode\Processor;

use Thunder\Shortcode\ExtractorInterface;
use Thunder\Shortcode\HandlerInterface;
use Thunder\Shortcode\Match;
use Thunder\Shortcode\ParserInterface;
use Thunder\Shortcode\ProcessorInterface;
use Thunder\Shortcode\Shortcode;
use Thunder\Shortcode\Shortcode\ShortcodeInterface;

/**
 * @author Tomasz Kowalczyk <tomasz@kowalczyk.cc>
 */
final class Processor implements ProcessorInterface
    {
    private $handlers = array();
    private $extractor;
    private $parser;
    private $defaultHandler;
    private $recursionDepth = null; // infinite recursion
    private $maxIterations = 1; // one iteration
    private $autoProcessContent = true; // automatically process shortcode content
    private $shortcodeBuilder;

    public function __construct(ExtractorInterface $extractor, ParserInterface $parser)
        {
        $this->extractor = $extractor;
        $this->parser = $parser;

        $this->shortcodeBuilder = function(array $c) {
            /** @var $s ShortcodeInterface */
            $s = $c['shortcode'];
            $namePosition = array_key_exists($s->getName(), $c['namePosition']) ? $c['namePosition'][$s->getName()] : 1;

            return new Shortcode\ProcessedShortcode($c['shortcode'], $c['parent'],
                $c['position'], $namePosition,
                $c['text'], $c['textPosition'], $c['textMatch'],
                $c['iterationNumber'], $c['recursionLevel'], $c['processor']);
            };
        }

    /**
     * Registers handler for given shortcode name.
     *
     * @param string $name
     * @param callable|HandlerInterface $handler
     *
     * @return self
     */
    public function addHandler($name, $handler)
        {
        $this->guardHandler($handler);

        if(!$name || $this->hasHandler($name))
            {
            $msg = 'Invalid name or duplicate shortcode handler for %s!';
            throw new \RuntimeException(sprintf($msg, $name));
            }

        $this->handlers[$name] = $handler;

        return $this;
        }

    /**
     * Registers handler alias for given shortcode name, which means that
     * handler for target name will be called when alias is found.
     *
     * @param string $alias Alias shortcode name
     * @param string $name Aliased shortcode name
     *
     * @return self
     */
    public function addHandlerAlias($alias, $name)
        {
        $handler = $this->getHandler($name);

        $this->addHandler($alias, function(Shortcode $shortcode) use($handler) {
            return call_user_func_array($handler, array($shortcode));
            });

        return $this;
        }

    /**
     * Default library behavior is to ignore and return matches of shortcodes
     * without handler just like they were found. With this callable being set,
     * all matched shortcodes without registered handler will be passed to it.
     *
     * @param callable|HandlerInterface $handler Handler for shortcodes without registered name handler
     */
    public function setDefaultHandler($handler)
        {
        $this->guardHandler($handler);

        $this->defaultHandler = $handler;
        }

    /**
     * Entry point for shortcode processing. Implements iterative algorithm for
     * both limited and unlimited number of iterations.
     *
     * @param string $text Text to process
     *
     * @return string
     */
    public function process($text)
        {
        $iterations = $this->maxIterations === null ? 1 : $this->maxIterations;
        $context = array(
            'processor' => $this,
            'iterationNumber' => 0,
            'recursionLevel' => 0,
            'position' => 0,
            'namePosition' => array(),
            'parent' => null,
            );

        while($iterations--)
            {
            $context['iterationNumber']++;
            $newText = $this->processIteration($text, $context);
            if($newText === $text)
                {
                break;
                }
            $text = $newText;
            $iterations += $this->maxIterations === null ? 1 : 0;
            }

        return $text;
        }

    private function processIteration($text, array &$context)
        {
        if(null !== $this->recursionDepth && $context['recursionLevel'] > $this->recursionDepth)
            {
            return $text;
            }

        $context['text'] = $text;
        $matches = $this->extractor->extract($text);
        $replaces = array();
        foreach($matches as $match)
            {
            $context['textMatch'] = $match->getString();
            $context['textPosition'] = $match->getPosition();
            $replaces[] = $this->processMatch($match, $context);
            }
        $replaces = array_reverse(array_filter($replaces));

        return array_reduce($replaces, function($state, array $item) {
            return substr_replace($state, $item[0], $item[1], $item[2]);
            }, $text);
        }

    private function processMatch(Match $match, array &$context)
        {
        $shortcode = $this->parser->parse($match->getString());
        $context['position']++;
        $context['namePosition'][$shortcode->getName()] = array_key_exists($shortcode->getName(), $context['namePosition'])
            ? $context['namePosition'][$shortcode->getName()] + 1
            : 1;

        /** @var $shortcode ShortcodeInterface */
        $context['shortcode'] = $shortcode;
        $shortcode = call_user_func_array($this->shortcodeBuilder, array($context));
        if($this->autoProcessContent && $shortcode->hasContent())
            {
            $context['recursionLevel']++;
            $context['parent'] = $shortcode;
            $content = $this->processIteration($shortcode->getContent(), $context);
            $shortcode = $shortcode->withContent($content);
            $context['parent'] = null;
            $context['recursionLevel']--;
            }

        $handler = $this->getHandler($shortcode->getName());
        if(!$handler)
            {
            return null;
            }

        $replace = $this->callHandler($handler, $shortcode, $match->getString());

        return array($replace, $match->getPosition(), $match->getLength());
        }

    /**
     * Recursion depth level, null means infinite, any integer greater than or
     * equal to zero sets value (number of recursion levels). Zero disables
     * recursion.
     *
     * @param int|null $depth
     *
     * @return self
     */
    public function setRecursionDepth($depth)
        {
        if(null !== $depth && !(is_int($depth) && $depth >= 0))
            {
            $msg = 'Recursion depth must be null (infinite) or integer >= 0!';
            throw new \InvalidArgumentException($msg);
            }

        $this->recursionDepth = $depth;

        return $this;
        }

    /**
     * Maximum number of iterations, null means infinite, any integer greater
     * than zero sets value. Zero is invalid because there must be at least one
     * iteration.
     *
     * @param int|null $iterations
     *
     * @return self
     */
    public function setMaxIterations($iterations)
        {
        if(null !== $iterations && !(is_int($iterations) && $iterations > 0))
            {
            $msg = 'Maximum number of iterations must be null (infinite) or integer > 0!';
            throw new \InvalidArgumentException($msg);
            }

        $this->maxIterations = $iterations;

        return $this;
        }

    /**
     * Whether shortcode content will be automatically processed and handler
     * already receives shortcode with processed content. If false, every
     * shortcode handler needs to process content on its own.
     *
     * @param bool $flag True if enabled (default), false otherwise
     *
     * @return self
     */
    public function setAutoProcessContent($flag)
        {
        $this->autoProcessContent = (bool)$flag;

        return $this;
        }

    /**
     * @deprecated Use self::setRecursionDepth() instead
     *
     * @param bool $recursion
     *
     * @return self
     */
    public function setRecursion($recursion)
        {
        return $this->setRecursionDepth($recursion ? null : 0);
        }

    private function guardHandler($handler)
        {
        if(!is_callable($handler) && !$handler instanceof HandlerInterface)
            {
            $msg = 'Shortcode handler must be callable or implement HandlerInterface!';
            throw new \RuntimeException(sprintf($msg));
            }
        }

    private function callHandler($handler, ShortcodeInterface $shortcode, $string)
        {
        if($handler instanceof HandlerInterface)
            {
            return $handler->isValid($shortcode)
                ? $handler->handle($shortcode)
                : $string;
            }

        return call_user_func_array($handler, array($shortcode));
        }

    private function getHandler($name)
        {
        return $this->hasHandler($name)
            ? $this->handlers[$name]
            : ($this->defaultHandler ? $this->defaultHandler : null);
        }

    private function hasHandler($name)
        {
        return array_key_exists($name, $this->handlers);
        }
    }
