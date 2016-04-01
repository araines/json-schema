<?php

namespace JsonSchema;

class PointerResolverTest extends \PHPUnit_Framework_TestCase
{
    private function performPointerResolve($schema, $pointer)
    {
        $refResolver = $this->getMock('JsonSchema\RefResolver', array('fetchRef'));
        $refResolver->expects($this->any())
            ->method('fetchRef')
            ->will($this->returnValue($schema));
        $referenceObject = (object)['$ref' => ('#' . $pointer)];
        $reference = new Reference($refResolver, '', $referenceObject);
        $reference->resolve();

        return $referenceObject;
    }

    public function testCanRetrieveRootPointer()
    {
        $schema = json_decode('{ "data": [ "a", "b", "c" ] }');
        $this->assertSame($schema, $this->performPointerResolve($schema, ''));
    }

    public function testCanRetrieveArrayElement()
    {
        $schema = json_decode('[ {"a":"a"}, {"b":"b"}, {"c":"c"} ]');
        $this->assertEquals((object)["c"=>"c"], $this->performPointerResolve($schema, '/2'));
    }

    public function testCanRetrieveArrayElementInsideObject()
    {
        $schema = json_decode('{ "data": [ {"a":"a"}, {"b":"b"}, {"c":"c"} ] }');
        $this->assertEquals((object)['b'=>'b'], $this->performPointerResolve($schema, '/data/1'));
    }

    public function testCanRetrieveDeepArrayReference()
    {
        $schema = json_decode('[ { "a": {"o":2} }, "b", "c" ]');
        $this->assertEquals((object)['o'=>2], $this->performPointerResolve($schema, '/0/a'));
    }

    public function testCanRetrieveLastArrayElement()
    {
        $schema = json_decode('{ "data": [ {"a":"a"}, {"b":"b"}, {"c":"c"} ] }');
        $this->assertEquals((object)['c'=>'c'], $this->performPointerResolve($schema, '/data/-'));
    }

    public function testCanRetrieveKeyWithSlash()
    {
        $schema = json_decode('{ "a/b.txt": {"idx":123} }');
        $this->assertEquals((object)['idx'=>123], $this->performPointerResolve($schema, '/a%2Fb.txt'));
    }

    public function testCanRetrieveViaEscapedSequences()
    {
        $schema = json_decode('{"a/b/c": {"o":1}, "m~n": {"i":8}, "a": {"b": {"c": {"r":12}} } }');

        $this->assertEquals((object)['o'=>1], $this->performPointerResolve($schema, '/a~1b~1c'));
        $this->assertEquals((object)['i'=>8], $this->performPointerResolve($schema, '/m~0n'));
        $this->assertEquals((object)['r'=>12], $this->performPointerResolve($schema, '/a/b/c'));
    }

    /**
     * @dataProvider specialCasesProvider
     */
    public function testCanEvaluateSpecialCases($expected, $pointerValue)
    {
        $schema =
            json_decode('{"foo":[{"bar":"b"},{"baz":"z"}],"":{"o":0},"a/b":{"a":1},"c%d":{"b":2},"e^f":{"c":3},"g|h":{"d":4},"k\"l":{"f":6}," ":{"g":7},"m~n":{"h":8}}');

        $this->assertEquals($expected, $this->performPointerResolve($schema, $pointerValue));
    }

    /**
     * @expectedException \JsonSchema\Exception\InvalidPointerException
     * @dataProvider      invalidPointersProvider
     */
    public function testInvalidPointersThrowsInvalidPointerException($pointerValue)
    {
        $schema = json_decode('{ "a": {"o":1} }');
        $this->performPointerResolve($schema, $pointerValue);
    }

    /**
     * @expectedException \JsonSchema\Exception\ResourceNotFoundException
     * @dataProvider      nonExistantPointersProvider
     */
    public function testFailureToResolvePointerThrowsResourceNotFoundException($jsonString, $pointerValue)
    {
        $schema = json_decode($jsonString);
        $this->performPointerResolve($schema, $pointerValue);
    }

    public function specialCasesProvider()
    {
        return array(
          array(json_decode('{"foo":[{"bar":"b"},{"baz":"z"}],"":{"o":0},"a/b":{"a":1},"c%d":{"b":2},"e^f":{"c":3},"g|h":{"d":4},"k\"l":{"f":6}," ":{"g":7},"m~n":{"h":8}}'), ''),
          array((object)['bar'=>'b'], '/foo/0'),
          array((object)['o'=>0], '/'),
          array((object)['a'=>1], '/a~1b'),
          array((object)['b'=>2], '/c%d'),
          array((object)['c'=>3], '/e^f'),
          array((object)['d'=>4], '/g|h'),
          array((object)['f'=>6], "/k\"l"),
          array((object)['g'=>7], '/%20'),
          array((object)['h'=>8], '/m~0n'),
        );
    }

    public function invalidPointersProvider()
    {
        return array(
            // Invalid starting characters
            array('*'),
            array('#'),
            array(15),
        );
    }

    public function nonExistantPointersProvider()
    {
        return array(
            array('[ "a", "b", "c" ]', '/3'),
            array('{ "data": { "a": {"b": "c"} } }', '/data/b'),
        );
    }
}
