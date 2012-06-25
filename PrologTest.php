<?php
//error_reporting(E_ALL && ~E_NOTICE);

require_once(__DIR__ . '/solver.php');

/**
 * Unit test for solver : does some queries and checking no regression
 *
 * @author flo
 */
class PrologTest extends PHPUnit_Framework_TestCase {

    protected function setUp() {
        $this->program = file_get_contents('fixtures_test.pro');
    }

    protected function tearDown() {
        unset($this->program);
    }

    public function queryProvider() {
        return array(
        //    array('bagof(c, triple(sc, A, B), L), length(L, N)', 'N', 21),
            array('factorial(6, X)', 'X', 720),
            array('qsort([5,3,2,111,88,9,7], X)', 'X', '[2, 3, 5, 7, 9, 88, 111]')
        );
    }

    /**
     * @dataProvider queryProvider
     */
    public function testQuery($query, $name, $val) {
        $result = execute($this->program, $query);
        $this->assertEquals($val, $result->$name);
    }

    public function testLessOrEqual() {
        $result = execute($this->program, 'leq(33, 7)');
        $this->assertTrue($result->isSuccess());
        $result = execute($this->program, 'leq(3, 7)');
        $this->assertFalse($result->isSuccess());
        $result = execute($this->program, 'leq(42, 42)');
        $this->assertTrue($result->isSuccess());
    }

    public function testGreater() {
        $result = execute($this->program, 'gtr(7, 33)');
        $this->assertTrue($result->isSuccess());
        $result = execute($this->program, 'gtr(77, 33)');
        $this->assertFalse($result->isSuccess());
    }

    public function testAddition() {
        $result = execute($this->program, 'add(7, 33, X)');
        $this->assertEquals(40, $result->X);
        $result = execute($this->program, 'add(-42, 42, X)');
        $this->assertEquals(0, $result->X);
    }

    public function testSubstraction() {
        $result = execute($this->program, 'sub(1, 1, X)');
        $this->assertEquals(0, $result->X);
        $result = execute($this->program, 'sub(7, 2, X)');
        $this->assertEquals(5, $result->X);
    }

    public function testMultiplication() {
        $result = execute($this->program, 'mul(42, 0, X)');
        $this->assertEquals(0, $result->X);
        $result = execute($this->program, 'mul(1, 1, X)');
        $this->assertEquals(1, $result->X);
        $result = execute($this->program, 'mul(7, 6, X)');
        $this->assertEquals(42, $result->X);
    }

    public function testUnify() {
        $result = execute($this->program, 'unify(7, 33)');
        $this->assertFalse($result->isSuccess());
        $result = execute($this->program, 'unify(42, 42)');
        $this->assertTrue($result->isSuccess());
        $result = execute($this->program, 'unify(X, 666)');
        $this->assertEquals(666, $result->X);
        $result = execute($this->program, 'unify(666, X)');
        $this->assertEquals(666, $result->X);
    }

    /**
     * @depends testUnify
     */
    public function testNot() {
        $result = execute($this->program, 'not(unify(7, 33))');
        $this->assertTrue($result->isSuccess());
        $result = execute($this->program, 'not(unify(666, 666))');
        $this->assertFalse($result->isSuccess());
    }

}