<?php

/**
 * @copyright 2017 Vladimir Jimenez
 * @license   https://github.com/allejo/stakx/blob/master/LICENSE.md MIT
 */

namespace allejo\stakx\Twig;

class ZipFilter implements StakxTwigFilter
{
    public function __invoke(array $array1, array $array2, $glue = '', $strict = false)
    {
        $result = array();
        $arr1_length = count($array1);
        $arr2_length = count($array2);

        for ($i = 0; $i < $arr1_length; $i++)
        {
            if ($i >= $arr2_length)
            {
                break;
            }

            $result[] = self::safe_get($array1, $i) . $glue . self::safe_get($array2, $i);
        }

        if (!$strict)
        {
            if ($arr2_length > $arr1_length)
            {
                $result = array_merge($result, array_slice($array2, $arr1_length));
            }
            else
            {
                $result = array_merge($result, array_slice($array1, $arr2_length));
            }
        }

        return $result;
    }

    public static function get()
    {
        return new \Twig_SimpleFilter('zip', new self());
    }

    private static function safe_get(array &$array, $key, $default = '')
    {
        return (isset($array[$key]) ? (string)$array[$key] : $default);
    }
}