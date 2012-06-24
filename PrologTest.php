<?php

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
            array('bagof(c, triple(sc, A, B), L), length(L, N)', 'N', 21),
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

}