<?php

namespace JsonSchema;

use JsonSchema\Exception\InvalidArgumentException;
use JsonSchema\Exception\InvalidPointerException;
use JsonSchema\Exception\ResourceNotFoundException;
use JsonSchema\Uri\UriResolver;

/**
 * This is a value class representing a `$ref` reference in a JSON schema
 *
 * We resolve JSON Pointers (RFC 6901) in the references
 */
class Reference
{
    const EMPTY_ELEMENT = '_empty_';
    const LAST_ELEMENT = '-';
    const SEPARATOR = '/';
    const TILDE = '~';

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
     * The URI of the schema where the reference was found
     *
     * @var string
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
            $pointer = $this->parseAndValidateRefString(); // This call will set $this->uri
            $this->parts = $this->decodePointer($pointer);
        }

        $this->state = static::STATE_UNRESOLEVED;
    }

    private function doResolve()
    {
        // It is important to finish fetching the schema before we change the state.
        // (So that when fetching a schema the resolving of this reference might be suspended
        // in order to resolve other references first.)
        $schema = $this->fetchSchema();

        // Check if this reference has already been resolved by another call to resolve
        if (static::STATE_RESOLVED == $this->state) {
            // just return the resolved object
            return $this->referencedObject;
        }
        
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
     * @param array  $parts  The (remaining) parts of the pointer.
     *
     * @throws ResourceNotFoundException
     *
     * @return mixed
     */
    private function doResolvePointer($schema, $parts)
    {
        // Resolve any other schema in the path
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
        if (!is_string($this->refString)) {
            throw new InvalidPointerException('Reference is not a string');
        }

        $resolver = new UriResolver();
        $this->uri = $resolver->extractLocation($this->refString);

        // Extract the pointer
        $pointer = $resolver->extractFragment($this->refString);

        if (!$pointer) {
            return ''; // Converting null to empty string
        }

        if (!is_string($pointer)) {
            throw new InvalidPointerException('Pointer is not a string');
        }

        $firstCharacter = substr($pointer, 0, 1);

        if ($firstCharacter !== self::SEPARATOR) {
            throw new InvalidPointerException('Pointer starts with invalid first character ' . $firstCharacter);
        }

        return $pointer;
    }

    /**
     * Split a pointer into parts and decode any escaped sequences.
     *
     * @param $pointer string The pointer of the reference (the part after the #)
     *
     * @return array $parts
     */
    private function decodePointer($pointer)
    {
        $parts = array_slice(explode(self::SEPARATOR, $pointer), 1);

        $mappings = array(
            '~1' => self::SEPARATOR,
            '~0' => self::TILDE,
        );

        foreach ($parts as &$part) {
            $part = strtr($part, $mappings);
            $part = urldecode($part);
        }

        return $parts;
    }
}
