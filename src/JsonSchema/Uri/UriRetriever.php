<?php

/*
 * This file is part of the JsonSchema package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JsonSchema\Uri;

use JsonSchema\PointerResolver;
use JsonSchema\Uri\Retrievers\FileGetContents;
use JsonSchema\Uri\Retrievers\UriRetrieverInterface;
use JsonSchema\Validator;
use JsonSchema\Exception\InvalidSchemaMediaTypeException;
use JsonSchema\Exception\JsonDecodingException;
use JsonSchema\Exception\ResourceNotFoundException;

/**
 * Retrieves JSON Schema URIs
 *
 * @author Tyler Akins <fidian@rumkin.com>
 */
class UriRetriever
{
    /**
     * @var null|UriRetrieverInterface
     */
    protected $uriRetriever = null;

    /**
     * @var array|object[]
     * @see loadSchema
     */
    private $schemaCache = array();

    /**
     * Guarantee the correct media type was encountered
     *
     * @param UriRetrieverInterface $uriRetriever
     * @param string $uri
     * @return bool|void
     */
    public function confirmMediaType($uriRetriever, $uri)
    {
        $contentType = $uriRetriever->getContentType();

        if (is_null($contentType)) {
            // Well, we didn't get an invalid one
            return;
        }

        if (Validator::SCHEMA_MEDIA_TYPE === $contentType) {
            return;
        }

        if (substr($uri, 0, 23) == 'http://json-schema.org/') {
            //HACK; they deliver broken content types
            return true;
        }

        throw new InvalidSchemaMediaTypeException(sprintf('Media type %s expected', Validator::SCHEMA_MEDIA_TYPE));
    }

    /**
     * Get a URI Retriever
     *
     * If none is specified, sets a default FileGetContents retriever and
     * returns that object.
     *
     * @return UriRetrieverInterface
     */
    public function getUriRetriever()
    {
        if (is_null($this->uriRetriever)) {
            $this->setUriRetriever(new FileGetContents);
        }

        return $this->uriRetriever;
    }

    /**
     * Retrieve a URI
     *
     * @param string $uri JSON Schema URI
     * @param string|null $baseUri
     * @return object JSON Schema contents
     */
    public function retrieve($uri, $baseUri = null)
    {
        $resolver = new UriResolver();
        $resolvedUri = $fetchUri = $resolver->resolve($uri, $baseUri);

        //fetch URL without #fragment
        $fetchUri = $resolver->extractLocation($fetchUri);

        $jsonSchema = $this->loadSchema($fetchUri);

        if ($jsonSchema instanceof \stdClass) {
            $jsonSchema->id = $resolvedUri;
        }

        return $jsonSchema;
    }

    /**
     * Fetch a schema from the given URI, json-decode it and return it.
     * Caches schema objects.
     *
     * @param string $fetchUri Absolute URI
     *
     * @return object JSON schema object
     */
    protected function loadSchema($fetchUri)
    {
        if (isset($this->schemaCache[$fetchUri])) {
            return $this->schemaCache[$fetchUri];
        }

        $uriRetriever = $this->getUriRetriever();
        $contents = $this->uriRetriever->retrieve($fetchUri);
        $this->confirmMediaType($uriRetriever, $fetchUri);
        $jsonSchema = json_decode($contents);

        if (JSON_ERROR_NONE < $error = json_last_error()) {
            throw new JsonDecodingException($error);
        }

        $this->schemaCache[$fetchUri] = $jsonSchema;

        return $jsonSchema;
    }

    /**
     * Set the URI Retriever
     *
     * @param UriRetrieverInterface $uriRetriever
     * @return $this for chaining
     */
    public function setUriRetriever(UriRetrieverInterface $uriRetriever)
    {
        $this->uriRetriever = $uriRetriever;

        return $this;
    }
}
