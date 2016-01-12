<?php

/**
 * Perform a recursive search in a "multidimensional array"
 * @param scalar $needle The element to search for
 * @param array $haystack The array to search in
 * @param bool $strict True to perform a strict (===) check
 * @return false|array Returns an array of path keys, or false if the needle couldn't be found in the haystack
 */
function array_search_recursive( $needle, $haystack, $strict = false ) {
    foreach ( $haystack as $haystackkey => $haystackelement ) {
        if ( $strict && $needle === $haystackelement ) {
            return (array) $haystackkey;
        }
        if ( !$strict && $needle == $haystackelement ) {
            return (array) $haystackkey;
        }
        if ( is_array( $haystackelement ) && ( $found = \array_search_recursive( $needle, $haystackelement, $strict ) ) !== false ) {
            $keylist = \array_merge( (array) $haystackkey, $found );
            return $keylist;
        }
    }
    return false;
}
