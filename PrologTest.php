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
            array('factorial(6, X)', 'X', 720),
            array('qsort([5,3,2,111,88,9,7], X)', 'X', '[2, 3, 5, 7, 9, 88, 111]')
        );
    }

    public function randomList() {
        $borneSup = 20;
        $std = range(0, $borneSup);
        $retour = array();
        for ($i = 0; $i < 4; $i++) {
            shuffle($std);
            $retour[] = array('[' . implode(',', $std) . ']', $borneSup);
        }

        return $retour;
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

    public function testCut() {
        $result = execute($this->program, 'max(111, 666, X)');
        $this->assertEquals(666, $result->X);
        $result = execute($this->program, 'max(111, 66, X)');
        $this->assertEquals(111, $result->X);
        $result = execute($this->program, 'max(111, 66, 111)');
        $this->assertTrue($result->isSuccess());
    }

    public function testLanguage() {
        $result = execute($this->program, 'genre(Mot,masculin)');
        $this->assertEquals(array('le', 'chat', 'blanc', 'rouge'), $result->Mot);
    }

    public function testComplexQuerySingle() {
        $result = execute($this->program, 'p(S,le,chat)');
        $this->assertEquals('snm(determinant(le), nom(chat), masculin)', $result->S);
    }

    public function testComplexQueryMulti_1() {
        $result = execute($this->program, 'p(S,X,rouge)');
        $this->assertEquals(array('souris', 'chat'), $result->X);
    }

    /**
     * @dataProvider randomList
     */
    public function testMaxList($tab, $maxi) {
        $result = execute($this->program, "maxlist(X, $tab)");
        $this->assertEquals($maxi, $result->X);
    }

}