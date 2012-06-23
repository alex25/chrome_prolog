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
        return array(array($fixtures, 'bagof(c, triple(sc, A, B), L), length(L, N) # L should have 21 elements'));
    }

    /**
     * @dataProvider queryProvider
     */
    public function testQuery($db, $query) {
        execute($db, $query);
    }

}