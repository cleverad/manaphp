<?php
namespace Tests;

use ManaPHP\Di\FactoryDefault;
use ManaPHP\Mongodb;
use MongoDB\BSON\ObjectID;
use PHPUnit\Framework\TestCase;
use Tests\Mongodb\Models\Actor;
use Tests\Mongodb\Models\City;
use Tests\Mongodb\Models\City1;
use Tests\Mongodb\Models\City2;
use Tests\Mongodb\Models\City3;
use Tests\Mongodb\Models\DataType;
use Tests\Mongodb\Models\Student;

class MongodbModelTest extends TestCase
{
    /**
     * @var \ManaPHP\DiInterface
     */
    protected $di;

    public function setUp()
    {
        $this->di = new FactoryDefault();
        $this->di->identity->setClaims([]);
        $config = require __DIR__ . '/config.database.php';
        $this->di->setShared('mongodb', new Mongodb($config['mongodb']));
    }

    public function test_getConsistentValue()
    {
        $dt = new DataType();
        $this->assertSame('manaphp', $dt->getNormalizedValue('string', 'manaphp'));
        $this->assertSame('123', $dt->getNormalizedValue('string', 123));

        $this->assertSame(123, $dt->getNormalizedValue('integer', 123));
        $this->assertSame(123, $dt->getNormalizedValue('integer', '123'));

        $this->assertSame(1.23, $dt->getNormalizedValue('double', 1.23));
        $this->assertSame(1.23, $dt->getNormalizedValue('double', '1.23'));

        $objectId = new ObjectID();
        $this->assertEquals($objectId, $dt->getNormalizedValue('objectid', $objectId));

        $this->assertEquals(new ObjectID('123456789012345678901234'), $dt->getNormalizedValue('objectid', '123456789012345678901234'));

        $this->assertTrue($dt->getNormalizedValue('boolean', true));
        $this->assertTrue($dt->getNormalizedValue('boolean', 1));
        $this->assertFalse($dt->getNormalizedValue('boolean', 0));
    }

    public function test_count()
    {
        $this->assertInternalType('int', City::count());

        $this->assertEquals(600, City::count());
        $this->assertEquals(3, City::count(['country_id' => 2]));
    }

    public function test_sum()
    {
        $avg = City::sum('city_id');
        $this->assertEquals('integer', gettype($avg));
        $this->assertEquals(180300, round($avg, 2));

        $avg = City::sum('city_id', ['country_id' => 2]);
        $this->assertEquals('integer', gettype($avg));
        $this->assertEquals(605, round($avg, 2));
    }

    public function test_max()
    {
        $this->assertEquals(600, City::max('city_id'));
        $this->assertEquals(483, City::max('city_id', ['country_id' => 2]));
    }

    public function test_min()
    {
        $this->assertEquals(600, City::max('city_id'));
        $this->assertEquals(483, City::max('city_id', ['country_id' => 2]));
    }

    public function test_avg()
    {
        $avg = City::avg('city_id');
        $this->assertEquals('double', gettype($avg));
        $this->assertEquals(300.5, round($avg, 2));

        $avg = City::avg('city_id', ['country_id' => 1]);
        $this->assertEquals('double', gettype($avg));
        $this->assertEquals(251, round($avg, 2));
    }

    public function test_first()
    {
        $actor = Actor::first([]);
        $this->assertTrue(is_object($actor));
        $this->assertInstanceOf(get_class(new Actor()), $actor);
        $this->assertInstanceOf('ManaPHP\Mongodb\Model', $actor);

        $this->assertTrue(is_object(Actor::first(['actor_id' => 1])));

        $actor = Actor::first(10);
        $this->assertInstanceOf(get_class(new Actor()), $actor);
        $this->assertEquals('10', $actor->actor_id);

        $actor = Actor::first(['actor_id' => 5]);
        $this->assertEquals(5, $actor->actor_id);

        $actor = Actor::first(['actor_id' => 5, 'first_name' => 'JOHNNY']);
        $this->assertEquals(5, $actor->actor_id);

        $this->assertNotFalse(City::first('10'));
    }

    public function test_exists()
    {
        $this->assertFalse(Actor::exists(-1));
        $this->assertTrue(Actor::exists(1));

        $this->assertTrue(City::exists('1'));
    }

    public function test_first_usage()
    {
        $this->assertEquals(10, City::first(10)->city_id);
        $this->assertEquals(10, City::first(['city_id' => 10])->city_id);
    }

    public function test_all()
    {
        $actors = Actor::all();
        $this->assertTrue(is_array($actors));
        $this->assertCount(200, $actors);
        $this->assertInstanceOf(get_class(new Actor()), $actors[0]);
        $this->assertInstanceOf('ManaPHP\Mongodb\Model', $actors[0]);

        $this->assertCount(200, Actor::all([]));

        $this->assertCount(0, Actor::all(['actor_id' => -1]));
        $this->assertEquals([], Actor::all(['actor_id' => -1]));

        $cities = City::all(['country_id' => 2], ['order' => 'city desc']);
        $this->assertCount(3, $cities);
        $this->assertEquals(483, $cities[0]->city_id);
    }

    public function test_values()
    {
        $cities = City::values('city', [], ['size' => 10]);
        $this->assertCount(10, $cities);

        $cities = City::values('city', [], ['size' => 10, 'page' => 100000]);
        $this->assertCount(0, $cities);
    }

    public function test_all_usage()
    {
        $this->assertCount(3, City::all(['country_id' => 2]));
        $this->assertCount(3, City::all(['country_id' => 2], ['order' => 'city_id desc']));
        $this->assertCount(2, City::all(['country_id' => 2], ['limit' => 2]));
        $this->assertCount(1, City::all(['country_id' => 2], ['limit' => 1, 'offset' => 2]));
    }

    /**
     * @param \ManaPHP\Mongodb\Model $model
     */
    protected function _truncateTable($model)
    {
        /**
         * @var \ManaPHP\Db $db
         */
        $db = $this->di->getShared('mongodb');
        $db->truncate($model->getSource());
    }

    public function test_create()
    {
        $this->_truncateTable(new Student());

        $student = new Student();
        $student->id = 1;
        $student->age = 21;
        $student->name = 'mana';
        $student->create();

        $this->assertEquals(1, $student->id);

        $student = Student::first(['id' => 1]);
        $this->assertEquals(1, $student->id);
        $this->assertEquals(21, $student->age);
        $this->assertEquals('mana', $student->name);

        //fixed bug: if record is existed already
        $student = new Student();
        $student->id = 2;
        $student->age = 21;
        $student->name = 'mana';
        $student->create();
    }

    public function test_update()
    {
        $this->_truncateTable(new Student());

        $student = new Student();
        $student->id = 1;
        $student->age = 21;
        $student->name = 'mana';
        $student->create();

        $student = Student::first(['id' => 1]);
        $student->age = 22;
        $student->name = 'mana2';
        $student->update();

        $student = Student::first(['id' => 1]);
        $this->assertEquals(1, $student->id);
        $this->assertEquals(22, $student->age);
        $this->assertEquals('mana2', $student->name);

        $student->update();
    }

    public function test_updateAll()
    {
        $this->_truncateTable(new Student());

        $student = new Student();
        $student->id = 1;
        $student->age = 21;
        $student->name = 'mana';
        $student->create();

        $student = new Student();
        $student->id = 2;
        $student->age = 22;
        $student->name = 'mana2';
        $student->create();

        $this->assertEquals(2, Student::updateAll(['name' => 'm'], []));

        $student->update();
    }

    public function test_deleteAll()
    {
        $this->_truncateTable(new Student());

        $student = new Student();
        $student->id = 1;
        $student->age = 21;
        $student->name = 'mana';
        $student->create();

        $this->assertNotNull(Student::first(['id' => 1]));

        Student::deleteAll([]);
        $this->assertNull(Student::first(['id' => 1]));
    }

    public function test_assign()
    {
        //normal usage
        $city = new City();
        $city->assign(['city_id' => 1, 'city' => 'beijing'], []);
        $this->assertEquals(1, $city->city_id);
        $this->assertEquals('beijing', $city->city);

        //normal usage with whitelist
        $city = new City();
        try {
            $city->assign(['city_id' => 1, 'city' => 'beijing'], ['city_id']);
            $this->assertFalse('why not1!');
        } catch (\Exception $e) {

        }
    }

    public function test_getSource()
    {
        //infer the table name from table name
        $city = new City1();
        $this->assertEquals('city1', $city->getSource());

        //use getSource
        $city = new City2();
        $this->assertEquals('city', $city->getSource());

        //use setSource
        $city = new City3();
        $this->assertEquals('the_city', $city->getSource());
    }

    public function test_getSnapshotData()
    {
        $actor = Actor::first(1);
        $snapshot = $actor->getSnapshotData();
        unset($snapshot['_id']);
        $this->assertSame($snapshot, $actor->toArray());
    }

    public function test_getChangedFields()
    {
        $actor = Actor::first(1);

        $actor->first_name = 'abc';
        $actor->last_name = 'mark';
        $this->assertEquals(['first_name', 'last_name'], $actor->getChangedFields());
    }

    public function test_hasChanged()
    {
        $actor = Actor::first(1);

        $actor->first_name = 'abc';
        $this->assertTrue($actor->hasChanged('first_name'));
        $this->assertTrue($actor->hasChanged(['first_name']));
    }
}
