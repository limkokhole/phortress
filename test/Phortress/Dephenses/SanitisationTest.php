<?php
/**
 * Created by PhpStorm.
 * User: naomileow
 * Date: 19/11/14
 * Time: 3:35 PM
 */

namespace Phortress\Dephenses;


use Phortress\Dephenses\Taint\TaintEnvironment;
use PhpParser\Node\Expr\Variable;

class SanitisationTest extends \PHPUnit_Framework_TestCase {
	public function setUp() {
		// Load a program
		$this->file = realpath(__DIR__ . '/../Fixture/sanitisation_test_1.php');
		$this->program = loadGlassBoxProgram($this->file);
		$this->file1 = realpath(__DIR__ . '/../Fixture/sanitisation_test_2.php');
		$this->program1 = loadGlassBoxProgram($this->file1);
		$this->file2 = realpath(__DIR__ . '/../Fixture/sanitisation_test_3.php');
		$this->program2 = loadGlassBoxProgram($this->file2);
	}

	public function testSQLInjectionSanitisation(){
		$taintDephense = new Taint();
		$runResult = $taintDephense->run($this->program->parseTree);
		$this->assertEquals(0, count($runResult));
		$taint = $this->getVariableTaint($this->program->parseTree[3]->var);
		$this->assertEquals(Taint\Annotation::TAINTED, $taint);
	}

	public function testSQLInjectionSanitisation1(){
		$taintDephense = new Taint();
		$runResult = $taintDephense->run($this->program2->parseTree);
		$this->assertEquals(0, count($runResult));
		$taint = $this->getVariableTaint($this->program2->parseTree[2]->var);
		$this->assertEquals(Taint\Annotation::TAINTED, $taint);
	}

	public function testSQLInjectionInFunctionCallSanitisation(){
		$taintDephense = new Taint();
		$runResult = $taintDephense->run($this->program1->parseTree);
		$this->assertEquals(0, count($runResult));
		$taint = $this->getVariableTaint($this->program1->parseTree[2]->var);
		$this->assertEquals(Taint\Annotation::TAINTED, $taint);
	}

	public function getVariableTaint(Variable $var){
		$assignEnv = $var->environment->resolveVariable($var->name)->environment;
		$taintEnv = TaintEnvironment::getTaintEnvironmentFromEnvironment($assignEnv);
		$taintResult = $taintEnv->getTaintResult($var->name);
		return $taintResult->getTaint();
	}
}
 