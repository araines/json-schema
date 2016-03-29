<?php

/*
 * This file is part of the JsonSchema package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JsonSchema;

use JsonSchema\Exception\InvalidArgumentException;
use JsonSchema\Exception\JsonDecodingException;
use JsonSchema\Uri\Retrievers\UriRetrieverInterface;
use JsonSchema\Uri\UriRetriever;
use JsonSchema\Uri\UriResolver;

/**
 * Take in an object that's a JSON schema and take care of all $ref references
 *
 * @author Tyler Akins <fidian@rumkin.com>
 * @see    README.md
 */
class RefResolver
{
    const SELF_REF_LOCATION = '#';

    /**
     * @var UriRetrieverInterface
     */
    protected $uriRetriever = null;

    /**
     * @var array
     */
    protected $schemas = array();

    /**
     * @var array
     */
    protected $scopes = array();

    /**
     * @var array
     */
    protected $references = array();

    /**
     * @param UriRetriever $retriever
     */
    public function __construct($retriever = null)
    {
        $this->uriRetriever = $retriever;
    }

    /**
     * Retrieves a given schema given a ref and a source URI
     *
     * @param  string $ref       Reference from schema
     * @param  string $sourceUri URI where original schema was located
     * @return object            Schema
     */
    public function fetchRef($ref, $sourceUri)
    {
        // Get absolute uri
        $resolver = new UriResolver();
        $uri = $resolver->resolve($ref, $sourceUri);

        // Get the location
        $location = $resolver->extractLocation($uri);

        // Retrieve dereferenced schema
        if ($location == null) {
            $schema = $this->schemas[self::SELF_REF_LOCATION];
        } elseif (array_key_exists($location, $this->schemas)) {
            $schema = $this->schemas[$location];
        } else {
            $retriever = $this->getUriRetriever();
            $schema = $retriever->retrieve($location);

            $this->schemas[$location] = $schema;
            $this->resolve($schema, $location);
        }

        return $schema;

        /*
        // Resolve JSON pointer
        $retriever = $this->getUriRetriever();
        $object = $retriever->resolvePointer($schema, $fragment);

        if ($object instanceof \stdClass) {
            $object->id = $uri;
        }

        return $object;
        */
    }

    /**
     * Return the URI Retriever, defaulting to making a new one if one
     * was not yet set.
     *
     * @return UriRetriever
     */
    public function getUriRetriever()
    {
        if (is_null($this->uriRetriever)) {
            $this->setUriRetriever(new UriRetriever);
        }

        return $this->uriRetriever;
    }

    /**
     * Resolves all $ref references for a given schema.  Recurses through
     * the object to resolve references of any child schemas.
     *
     * The 'format' property is omitted because it isn't required for
     * validation.  Theoretically, this class could be extended to look
     * for URIs in formats: "These custom formats MAY be expressed as
     * an URI, and this URI MAY reference a schema of that format."
     *
     * The 'id' property is not filled in, but that could be made to happen.
     *
     * @param object $schema    JSON Schema to flesh out
     * @param string $sourceUri URI where this schema was located
     */
    public function resolve($schema, $sourceUri = null)
    {
        $this->findReferences($schema, $sourceUri);
        $this->resolveReferences();
    }

    private function resolveReferences()
    {
        while ($reference = array_shift($this->references)) {
            if ($reference instanceof Reference) {
                $reference->resolve();
            }
        }
    }

    private function findReferences(& $schema, $sourceUri = null)
    {
        if (!is_object($schema)) {
            return;
        }

        // Fill in id property
        if ($sourceUri) {
            $schema->id = $sourceUri;
        }

        // First determine our resolution scope
        $scope = $this->enterResolutionScope($schema, $sourceUri);

        // These properties are just schemas
        // eg.  items can be a schema or an array of schemas
        foreach (array('additionalItems', 'additionalProperties', 'extends', 'items') as $propertyName) {
            $this->resolveProperty($schema, $propertyName, $scope);
        }

        // These are all potentially arrays that contain schema objects
        // eg.  type can be a value or an array of values/schemas
        // eg.  items can be a schema or an array of schemas
        foreach (array('disallow', 'extends', 'items', 'type', 'allOf', 'anyOf', 'oneOf') as $propertyName) {
            $this->resolveArrayOfSchemas($schema, $propertyName, $scope);
        }

        // These are all objects containing properties whose values are schemas
        foreach (array('definitions', 'dependencies', 'patternProperties', 'properties') as $propertyName) {
            $this->resolveObjectOfSchemas($schema, $propertyName, $scope);
        }

        // Create Reference object if we have a $ref
        $this->createRef($schema, $scope);

        // Pop back out of our scope
        $this->leaveResolutionScope();
    }

    /**
     * Enters a new resolution scope for the given schema.  Inspects the
     * partial for the presence of 'id' and then returns that as a absolute
     * uri.  Returns the new scope.
     *
     * @param  object $schemaPartial JSON Schema to get the resolution scope for
     * @param  string $sourceUri     URI where this schema was located
     * @return string
     */
    private function enterResolutionScope($schemaPartial, $sourceUri)
    {
        if (count($this->scopes) === 0) {
            $this->scopes[] = self::SELF_REF_LOCATION;
            $this->schemas[self::SELF_REF_LOCATION] = $schemaPartial;
            $this->schemas[$sourceUri] = $schemaPartial;
        }

        if (!empty($schemaPartial->id)) {
            $resolver = new UriResolver();
            $this->scopes[] = $resolver->resolve($schemaPartial->id, $sourceUri);
        } else {
            $this->scopes[] = end($this->scopes);
        }

        return end($this->scopes);
    }

    /**
     * Leaves the current resolution scope.
     */
    private function leaveResolutionScope()
    {
        array_pop($this->scopes);
    }

    /**
     * Given an object and a property name, that property should be an
     * array whose values can be schemas.
     *
     * @param object $schema       JSON Schema to flesh out
     * @param string $propertyName Property to work on
     * @param string $sourceUri    URI where this schema was located
     */
    public function resolveArrayOfSchemas(& $schema, $propertyName, $sourceUri)
    {
        if (! isset($schema->$propertyName) || ! is_array($schema->$propertyName)) {
            return;
        }

        foreach ($schema->$propertyName as & $possiblySchema) {
            $this->findReferences($possiblySchema, $sourceUri);
        }
    }

    /**
     * Given an object and a property name, that property should be an
     * object whose properties are schema objects.
     *
     * @param object $schema       JSON Schema to flesh out
     * @param string $propertyName Property to work on
     * @param string $sourceUri    URI where this schema was located
     */
    public function resolveObjectOfSchemas($schema, $propertyName, $sourceUri)
    {
        if (! isset($schema->$propertyName) || ! is_object($schema->$propertyName)) {
            return;
        }

        foreach ($schema->$propertyName as & $possiblySchema) {
            $this->findReferences($possiblySchema, $sourceUri);
        }
    }

    /**
     * Given an object and a property name, that property should be a
     * schema object.
     *
     * @param object $schema       JSON Schema to flesh out
     * @param string $propertyName Property to work on
     * @param string $sourceUri    URI where this schema was located
     */
    public function resolveProperty($schema, $propertyName, $sourceUri)
    {
        if (! isset($schema->$propertyName)) {
            return;
        }

        $this->findReferences($schema->$propertyName, $sourceUri);
    }

    /**
     * Look for the $ref property in the object.  If found, create a reference to be resolved later.
     *
     * @param object $schema    JSON Schema to flesh out
     * @param string $sourceUri URI where this schema was located
     * @return Reference|null
     */
    public function createRef(& $schema, $sourceUri)
    {
        try {
            $reference = new Reference($this, $sourceUri, $schema);
        } catch (InvalidArgumentException $ex) {
            if ('Not a reference' == $ex->getMessage()) {
                // Ok, this is not a reference
                return null;
            }

            throw $ex;
        }

        $this->references[] = $reference;

        return $reference;
    }

    /**
     * Set URI Retriever for use with the Ref Resolver
     *
     * @param UriRetriever $retriever
     * @return $this for chaining
     */
    public function setUriRetriever(UriRetriever $retriever)
    {
        $this->uriRetriever = $retriever;

        return $this;
    }

    protected function resolveRefSegment($data, $pathParts)
    {
        foreach ($pathParts as $path) {
            $path = strtr($path, array('~1' => '/', '~0' => '~', '%25' => '%'));

            if (is_array($data)) {
                $data = $data[$path];
            } else {
                $data = $data->{$path};
            }
        }

        return $data;
    }
}
