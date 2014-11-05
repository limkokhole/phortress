<?php
namespace Phortress;

use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Node\Expr;

class EnvironmentResolver extends NodeVisitorAbstract {
	/**
	 * The global environment for the program.
	 *
	 * @var GlobalEnvironment
	 */
	private $globalEnvironment;

	/**
	 * The stack of environments while traversing the tree.
	 *
	 * @var Environment[]
	 */
	private $environmentStack = array();

	/**
	 * Constructor.
	 *
	 * @param GlobalEnvironment $globalEnvironment The global environment to use
	 * for traversal.
	 */
	public function __construct(GlobalEnvironment $globalEnvironment) {
		$this->globalEnvironment = $globalEnvironment;
	}

	public function beforeTraverse(array $nodes) {
		$this->environmentStack = array($this->globalEnvironment);
	}

	public function enterNode(Node $node) {
		if ($node instanceof Stmt\Function_) {
			$node->environment = $this->currentEnvironment()->
				createChildFunction($node);
			$this->pushEnvironment($node->environment);
		} else if ($node instanceof Expr\Assign) {
			$node->environment = $this->currentEnvironment()->
				defineVariableByValue($node);
		} else if ($node instanceof Node\Expr) {
			$node->environment = $this->currentEnvironment();
		}
	}

	public function leaveNode(Node $node) {
		if ($node instanceof Stmt\Function_) {
			$this->popEnvironment();
		}
	}

	/**
	 * Gets the current environment.
	 *
	 * @return Environment
	 */
	private function &currentEnvironment() {
		return $this->environmentStack[count($this->environmentStack) - 1];
	}

	/**
	 * Pops the topmost environment from the environment stack.
	 */
	private function popEnvironment() {
		array_pop($this->environmentStack);
		assert(!empty($this->environmentStack), 'Cannot pop the global ' .
			'environment off the environment stack.');
	}

	/**
	 * Pushes a new environment to the top of the environment stack.
	 * @param Environment $environment
	 */
	private function pushEnvironment(Environment $environment) {
		array_push($this->environmentStack, $environment);
	}
} 