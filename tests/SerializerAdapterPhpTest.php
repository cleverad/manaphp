<?php
namespace Tests;

use ManaPHP\Serializer\Adapter\Php;
use PHPUnit\Framework\TestCase;

class SerializerAdapterPhpTest extends TestCase
{
    public function test_serialize()
    {
        $serializer = new Php();

        $data = true;
        $this->assertSame($data, $serializer->deserialize($serializer->serialize($data)));

        $data = false;
        $this->assertSame($data, $serializer->deserialize($serializer->serialize($data)));

        $data = 1;
        $this->assertSame($data, $serializer->deserialize($serializer->serialize($data)));

        $data = '1';
        $this->assertSame($data, $serializer->deserialize($serializer->serialize($data)));

        $data = 'abc';
        $this->assertSame($data, $serializer->deserialize($serializer->serialize($data)));

        $data = [];
        $this->assertSame($data, $serializer->deserialize($serializer->serialize($data)));

        $data = ['ab' => 'abc'];
        $this->assertSame($data, $serializer->deserialize($serializer->serialize($data)));

        $data = new \stdClass();
        $data->a = 1;
        $data->b = 2;
        $this->assertEquals($data, $serializer->deserialize($serializer->serialize($data)));
        $this->assertInstanceOf('\stdClass', $serializer->deserialize($serializer->serialize($data)));
    }
}