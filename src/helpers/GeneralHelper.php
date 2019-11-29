<?php

namespace Globalis\MysqlDataAnonymizer\Helpers;

class GeneralHelper
{
    /**
     * Array only.
     *
     * @param array        $array
     * @param array|string $keys
     *
     * @return array
     */
    public static function arrayOnly($array, $keys)
    {
        return array_intersect_key($array, array_flip((array) $keys));
    }

    /**
     * Correctly quotes a string so that all strings are escaped.

     * @param s         the string to quote
     *
     * @return  quoted string to be sent back to database
     */
    function qstr($s)
    {
        $s = str_replace(array('\\',"\0"),array('\\\\',"\\\0"),$s);
        return  "'".str_replace("'","\\'",$s)."'";
    }
}
