<?php

/**
 * @copyright 2018 Vladimir Jimenez
 * @license   https://github.com/stakx-io/stakx/blob/master/LICENSE.md MIT
 */

namespace allejo\stakx\Utilities;

abstract class StrUtils
{
    /**
     * Interpolates context values into the message placeholders.
     *
     * @author PHP Framework Interoperability Group
     *
     * @param string $message
     * @param array  $context
     *
     * @return string
     */
    public static function interpolate($message, array $context)
    {
        // build a replacement array with braces around the context keys
        $replace = [];

        foreach ($context as $key => $val)
        {
            if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString')))
            {
                $replace[sprintf('{%s}', $key)] = $val;
            }
        }

        // interpolate replacement values into the message and return
        return strtr($message, $replace);
    }

    /**
     * Check if an object can be casted into a string.
     *
     * @param mixed $mixed
     *
     * @see https://stackoverflow.com/a/5496674
     *
     * @return bool
     */
    public static function canBeCastedToString($mixed)
    {
        if (is_string($mixed))
        {
            return true;
        }

        return
            (!is_array($mixed)) &&
            (
                (!is_object($mixed) && settype($mixed, 'string') !== false) ||
                (is_object($mixed) && method_exists($mixed, '__toString'))
            )
        ;
    }
}
