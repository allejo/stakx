<?php

/**
 * @copyright 2017 Vladimir Jimenez
 * @license   https://github.com/allejo/stakx/blob/master/LICENSE.md MIT
 */

namespace allejo\stakx\Object;

/**
 * Allows an object to be stored in a JailObject.
 *
 * @see JailObject
 */
interface Jailable
{
    /**
     * Create a JailObject instance from the object implementing this interface.
     *
     * @return JailObject
     */
    public function createJail();
}
