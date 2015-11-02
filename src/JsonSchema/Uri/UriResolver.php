<?php

/*
 * This file is part of the JsonSchema package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JsonSchema\Uri;

use JsonSchema\Exception\UriResolverException;

/**
 * Resolves JSON Schema URIs
 *
 * @author Sander Coolen <sander@jibber.nl>
 */
class UriResolver
{
    /**
     * Resolves a URI
     *
     * @param string $uri     Absolute or relative
     * @param string $baseUri Base URI (required to resolve relative URIs)
     *
     * @throws UriResolverException
     *
     * @return string Absolute URI
     */
    public function resolve($uri, $baseUri = null)
    {
        if ($uri == '') {
            return $baseUri;
        }

        $parts = $this->parse($uri);

        // Return if uri is already absolute
        if (isset($parts['scheme'])) {
            return $uri;
        }

        $uri = $this->parse($baseUri);

        // Replace scheme and host if specified in the new uri
        if (isset($parts['scheme'])) {
            $uri['scheme'] = $parts['scheme'];
        }
        if (isset($parts['host'])) {
            $uri['host'] = $parts['host'];
        }

        // Join the base URI path with the new path
        if (isset($parts['path'])) {
            if (isset($uri['path'])) {
                $uri['path'] = rtrim(str_replace(basename($uri['path']), '', $uri['path']), '/');
                $uri['path'] .= '/' . ltrim($parts['path'], '/');
            } else {
                $uri['path'] = $parts['path'];
            }
        }

        // Replace query and fragments
        if (isset($parts['query'])) {
            $uri['query'] = $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $uri['fragment'] = $parts['fragment'];
        }

        return $this->build($uri);
    }

    /**
     * @param string $uri
     * @return boolean
     */
    public function isValid($uri)
    {
        try {
            $this->parse($uri);
            $valid = true;
        } catch (UriResolverException $e) {
            $valid = false;
        }

        return $valid;
    }

    /**
     * Builds a URI based on URL parts
     *
     * @param  array  $parts
     * @return string
     */
    protected function build(array $parts)
    {
        $scheme   = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host     = isset($parts['host']) ? $parts['host'] : '';
        $port     = isset($parts['port']) ? ':' . $parts['port'] : '';
        $user     = isset($parts['user']) ? $parts['user'] : '';
        $pass     = isset($parts['pass']) ? ':' . $parts['pass']  : '';
        $pass     = ($user || $pass) ? "$pass@" : '';
        $path     = isset($parts['path']) ? $parts['path'] : '';
        $query    = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return "$scheme$user$pass$host$port$path$query$fragment";
    }

    /**
     * Parses a URI into parts
     *
     * @param  string $uri
     * @return array
     */
    protected function parse($uri)
    {
        $parts = parse_url($uri);
        if (false === $parts) {
            throw new UriResolverException("URI $uri was malformed and could not be parsed");
        }

        return $parts;
    }

}
