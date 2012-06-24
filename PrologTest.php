<?php

require_once(__DIR__ . '/solver.php');

/**
 * Unit test for solver : does some queries and checking no regression
 *
 * @author flo
 */
class PrologTest extends PHPUnit_Framework_TestCase {

    public function queryProvider() {
        $fixtures = file_get_contents('fixtures_test.pro');
        return array(
            array($fixtures, 'bagof(c, triple(sc, A, B), L), length(L, N)', 'N', 21),
            array($fixtures, 'factorial(6, X)', 'X', 720),
            array($fixtures, 'qsort([5,3,2,111,88,9,7], X)', 'X', '[2, 3, 5, 7, 9, 88, 111]')
        );
    }

    /**
     * @dataProvider queryProvider
     */
    public function testQuery($db, $query, $name, $val) {
        $result = execute($db, $query);
        $this->assertEquals($val, $result->$name);
    }
    
    public function testLessOrEqual() {
        $fixtures = file_get_contents('fixtures_test.pro');
        $result = execute($fixtures, 'leq(33, 7)');
        $this->assertTrue($result->isSuccess());
    }

}