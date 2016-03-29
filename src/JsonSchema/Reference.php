<?php

namespace JsonSchema;

use JsonSchema\Exception\InvalidArgumentException;
use JsonSchema\Exception\InvalidPointerException;
use JsonSchema\Exception\ResourceNotFoundException;
use JsonSchema\Uri\UriResolver;

/**
 * This is a value class representing a `$ref` reference in a JSON schema
 *
 * We also resolve JSON Pointers (RFC 6901) in the references
 */
class Reference
{
    const EMPTY_ELEMENT = '_empty_';
    const LAST_ELEMENT = '-';
    const SEPARATOR = '/';

    const REF = '$ref';

    const STATE_UNPARSED = 'unparsed';
    const STATE_UNRESOLEVED = 'unresolved';
    const STATE_RESOLVING = 'resolving';
    const STATE_RESOLVED = 'resolved';

    /**
     * The state of this reference
     *
     * @var string
     */
    private $state;

    /**
     * Object that resolves references in a newly gotten schema
     *
     * @var RefResolver
     */
    private $refResolver;

    /**
     * Pointer to the referenced object
     *
     * @var object
     */
    private $referencedObject;

    /**
     * The reference as a string
     *
     * @var string
     */
    private $refString;

    /**
     * The URI part of the reference
     *
     * @var string
     */
    private $uri;

    /**
     * @var The URI of the schema where the reference was found
     */
    private $sourceUri;

    /**
     * The reference as an array of parts
     *
     * @var array
     */
    private $parts;

    public function __construct(RefResolver $refResolver, $sourceUri, & $referencedObject = null)
    {
        $this->refResolver = $refResolver;
        $this->sourceUri = $sourceUri;
        $this->state = static::STATE_UNPARSED;

        if (isset($referencedObject)) {
            $this->setReferencedObject($referencedObject);
        }
    }

    public function setReferencedObject(& $referencedObject)
    {
        $this->referencedObject = & $referencedObject;
        $this->parseReferencedObject();
    }

    public function resolve()
    {
        switch ($this->state) {
            case static::STATE_UNPARSED:
                throw new \LogicException('Trying to resolve a reference that is not yet parsed');
            case static::STATE_UNRESOLEVED:
                // This is where we do the work of resolving this reference
                return $this->doResolve();
            case static::STATE_RESOLVING:
                // Make sure we do not enter endless recursion
                throw new ResourceNotFoundException('Impossible to resolve reference $ref: ' . $this->refString);
            case static::STATE_RESOLVED:
                // Resolving already done, do nothing
                return $this->referencedObject;
            default:
                // We should never get here
                throw new \LogicException('Invalid state ' . $this->state);
        }
    }

    private function parseReferencedObject()
    {
        if (!is_object($this->referencedObject)) {
            throw new InvalidArgumentException('Not an object');
        }

        $ref = static::REF;
        $originalObject = $this->referencedObject;

        if (!property_exists($originalObject, $ref)) {
            throw new InvalidArgumentException('Not a reference');
        }

        $this->referencedObject = $this; // Temporarily replace with this until the reference is resolved

        $this->refString = trim($originalObject->$ref);
        $this->parts = [];

        if (!empty($this->refString)) {
            // This call will set $this->uri
            $pointer = $this->parseAndValidateRefString();
            $this->parts = array_slice($this->decodeParts(explode('/', $pointer)), 1);
        }

        $this->state = static::STATE_UNRESOLEVED;
    }

    private function doResolve()
    {
        // It is important to finish fetching the schema before we change the state 
        $schema = $this->fetchSchema();
        
        // Now we start to resolve the reference, and we change the state
        $this->state = static::STATE_RESOLVING;
        $resolvedObject = $this->doResolvePointer($schema, $this->parts);

        if (!is_object($resolvedObject)) {
            throw new ResourceNotFoundException("Pointer was not an object");
        }

        $this->referencedObject = $resolvedObject;
        $this->state = static::STATE_RESOLVED;

        return $resolvedObject;
    }

    /**
     * @return object
     */
    private function fetchSchema()
    {
        return $this->refResolver->fetchRef($this->uri, $this->sourceUri);
    }

    /**
     * Recurse through $schema until location described by $parts is found.
     *
     * @param mixed  $schema The json document.
     * @param string $schema The original json pointer.
     * @param array  $parts  The (remaining) parts of the pointer.
     *
     * @throws ResourceNotFoundException
     *
     * @return mixed
     */
    private function doResolvePointer($schema, $parts)
    {
        // Resolve any other schema in path
        if ($schema instanceof static) {
            $schema = $schema->resolve();
        }

        // Check for completion
        if (count($parts) === 0) {
            return $schema;
        }

        $part = array_shift($parts);

        // Ensure we deal with empty keys the same way as json_decode does
        if ($part === '') {
            $part = self::EMPTY_ELEMENT;
        }

        if (is_object($schema) && property_exists($schema, $part)) {
            return $this->doResolvePointer($schema->$part, $parts);
        } elseif (is_array($schema)) {
            if ($part === self::LAST_ELEMENT) {
                return $this->doResolvePointer(end($schema), $parts);
            }
            if (filter_var($part, FILTER_VALIDATE_INT) !== false &&
                array_key_exists($part, $schema)
            ) {
                return $this->doResolvePointer($schema[$part], $parts);
            }
        }

        $message = "Failed to resolve pointer $this->refString from document id"
            . (isset($schema->id) ? $schema->id : '');
        throw new ResourceNotFoundException($message);
    }

    /**
     * Validate a reference and extract the pointer string.
     *
     * @throws InvalidPointerException
     */
    private function parseAndValidateRefString()
    {
        if ($this->refString !== '' && !is_string($this->refString)) {
            throw new InvalidPointerException('Reference is not a string');
        }

        $resolver = new UriResolver();
        $this->uri = $resolver->extractLocation($this->refString);
        $pointer = $resolver->extractFragment($this->refString);

        if (!$pointer) {
            return '';
        }

        if (!is_string($pointer)) {
            throw new InvalidPointerException('Pointer is not a string');
        }

        $firstCharacter = substr($pointer, 0, 1);

        if ($firstCharacter !== self::SEPARATOR) {
            throw new InvalidPointerException('Pointer starts with invalid character ' . $firstCharacter);
        }

        return $pointer;
    }

    /**
     * Decode any escaped sequences.
     *
     * @param array $parts The json pointer parts.
     *
     * @return array
     */
    private function decodeParts(array $parts)
    {
        $mappings = array(
            '~1' => '/',
            '~0' => '~',
        );

        foreach ($parts as &$part) {
            $part = strtr(urldecode($part), $mappings);
        }

        return $parts;
    }
}
