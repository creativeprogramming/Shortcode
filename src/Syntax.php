<?php
namespace Thunder\Shortcode;

use Thunder\Shortcode\Syntax\SyntaxInterface;

/**
 * @author Tomasz Kowalczyk <tomasz@kowalczyk.cc>
 */
class_alias('Thunder\\Shortcode\\Syntax\\Syntax', 'Thunder\\Shortcode\\Syntax', true);
return;

/**
 * This implementation is left only to not break IDE autocompletion, this class
 * is deprecated, it was moved to the new location as specified in docblock.
 * This file will be removed in version 1.0!
 *
 * @deprecated use Thunder\Shortcode\Syntax\Syntax
 * @codeCoverageIgnore
 */
final class Syntax implements SyntaxInterface
    {
    public function __construct()
        {
        }

    public static function create()
        {
        return new self();
        }

    public static function createStrict()
        {
        return new self();
        }

    public function getOpeningTag()
        {
        return '';
        }

    public function getClosingTag()
        {
        return '';
        }

    public function getClosingTagMarker()
        {
        return '';
        }

    public function getParameterValueSeparator()
        {
        return '';
        }

    public function getParameterValueDelimiter()
        {
        return '';
        }
    }
