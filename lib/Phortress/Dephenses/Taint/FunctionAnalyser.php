<?php
namespace Phortress\Dephenses\Taint;

use PhpParser\Node;
use PhpParser\Node\Expr;

class FunctionAnalyser{
    const TAINT_KEY = "taint";
    const SANITISATION_KEY = "sanfuncs";
    const VARIABLE_KEY = "variable";
    const UNRESOLVED_VARIABLE_KEY = "unresolved";
    const VARIABLE_DEF = "def";
    /**
     * Return statements and the variables they are dependent on.
     * array(Stmt => array(variable name => array(Variable Info, as stored in the $variables array))
     */
    protected $returnStmts = array();
    
    /**
     * The parameters to the function
     */
    protected $params = array();
    
    /**
     * The statements in the function
     */
    protected $functionStmts;
    
    /**
     * array(variable name => array(VARIABLE_KEY => variable, TAINT_KEY => taint,
     *  SANITISATION_KEY => array(sanitising functions)))
     */
    protected $variables = array();
    
    /**
     * Environment where the function was defined
     */
    protected $environment;
    
    protected $function;
    
    public function __construct(\Phortress\Environment $env, $functionName) {
        assert(!($functionName instanceof Expr));
        //For now we do not handle dynamic function names;
        $this->environment = $env;
        $this->function = $env->resolveFunction($functionName);
        $this->functionStmts = $this->function->stmts;
        $this->params = $this->function->params;
        $this->analyseReturnStatementsDependencies($this->functionStmts);
        $this->function->analyser = self;
    }

    private function analyseReturnStatementsDependencies($stmts){
        $retStmts = $this->getReturnStatements($stmts);
        $stmt_dependencies = array();
        foreach($retStmts as $ret){
            $depending_vars = $this->analyseStatementDependency($ret);
            $index = $ret->getLine(); //Use the statement's line number to index the statement for now.
            $stmt_dependencies[$index] = $depending_vars;
        }
        $this->returnStmts = $stmt_dependencies;
    }
    
    private function analyseStatementDependency(Return_ $stmt){
        $exp = $stmt->expr;
        $trace = array();
        if($exp instanceof Expr){
            $trace = $this->traceExpressionVariables($exp);
        }
        return $trace;
    }
    
    private function traceExpressionVariables(Expr $exp){
        if($exp instanceof Scalar){
            return array();
        }else if ($exp instanceof Variable) {
            return $this->traceVariable($exp);
        }else if($exp instanceof ClassConstFetch || ConstFetch){
            return array();
        }else if($exp instanceof PreInc || $exp instanceof PreDec || $exp instanceof PostInc || $exp instanceof PostDec){
            $var = $exp->var;
            return $this->traceVariable($var);
        }else if($exp instanceof BinaryOp){
            return $this->traceBinaryOp($exp);
        }else if($exp instanceof UnaryMinus || $exp instanceof UnaryPlus){
            $var = $exp->expr;
            return $this->traceVariable($var);
        }else if($exp instanceof Array_){
            return $this->traceVariablesInArray($exp);
        }else if($exp instanceof ArrayDimFetch){
            //For now treat all array dimension fields as one
            $var = $exp->var;
            return $this->traceVariable($var);
        }else if($exp instanceof PropertyFetch){
            $var = $exp->var;
            return $this->traceVariable($var);
        }else if($exp instanceof StaticPropertyFetch){
            //TODO:
        }else if($exp instanceof FuncCall){
            return $this->traceFunctionCall($exp);
        }else if($exp instanceof MethodCall){
            return $this->traceMethodCall($exp);
        }else if($exp instanceof Ternary){
            //If-else block
           return $this->traceTernaryTrace($exp);
        }else if($exp instanceof Eval_){
            return $this->resolveTernaryTrace($exp->expr);
        }else{
            //Other expressions we will not handle.
            return array();
        }
    }
    
    private static function traceFunctionCall(Expr\FuncCall $exp){
        $func_name = $exp->name;
        
    }
    
    private static function traceMethodCall(Expr\MethodCall $exp){
        
    }
    
     private static function resolveTernaryTrace(Ternary $exp){
        $if = $exp->if;
        $else = $exp->else;
        $if_trace = $this->traceExpressionVariables($if);
        $else_trace = $this->traceExpressionVariables($else);
        return $this->mergeTaintValues($if_trace, $else_trace);
    }
    
    private function traceVariablesInArray(Array_ $arr){
        $arr_items = $arr->items;
        $var_traces = array();
        foreach($arr_items as $item){
            $exp = $item->value;
            $var_traces[] = $this->traceExpressionVariables($exp);
        }
        return $this->mergeVariables($var_traces);
    }
    
    private function traceBinaryOp(BinaryOp $exp){
        $left = $exp->left;
        $right = $exp->right;
        $left_var = $this->traceVariable($left);
        $right_var = $this->traceVariable($right);
        return $this->mergeVariables(array($left_var, $right_var));
    }
    
    private function mergeVariables($vars){
        $merged = array();
        foreach($vars as $var){
            if(empty($var)){
                continue;
            }
            $var_name = key($var);
            if(!array_key_exists($var_name, $merged)){
                $merged[$var_name] = $var;
            }else{
                $existing = $merged[$var_name];
                $merged[$var_name] = $this->mergeVariableRecords($existing, $var);
            }
        }
    }
    
    private function mergeVariableRecords($var1, $var2){
        $taint = $this->mergeTaintValues($var1, $var2);
        $san = $this->mergeSanitisingFunctions($var1, $var2);
        $var_arr = $this->constructVariableDetails($var1[self::VARIABLE_KEY], $taint, $san);
        $var_arr[self::VARIABLE_DEF] = $var2[self::VARIABLE_DEF];
        return $var_arr;
    }
    
    private function mergeTaintValues($var1, $var2){
        return max($var1[self::TAINT_KEY], $var2[self::TAINT_KEY]);
    }
    
    private function mergeSanitisingFunctions($var1, $var2){
        $sanitising1 = $var1[self::SANITISATION_KEY];
        $sanitising2 = $var2[self::SANITISATION_KEY];
        return array_intersect($sanitising1, $sanitising2);
    }
    
    private function traceVariable(Variable $var){
        $name = $var->name;
        if($name instanceof Expr){
            $name = self::UNRESOLVED_VARIABLE_KEY;
        }
        $var_details = $this->getVariableDetails($var);
        $details_ret = array($name => $var_details);
        
        if(\Phortress\Dephenses\InputSources::isInputVariable($var)){
            $var_details[self::TAINT_KEY] = Annotation::TAINTED;
            return $details_ret;
        }
        
        if(!$this->isFunctionParameter($var)){
            $assign = $var_details[self::VARIABLE_DEF];
            if(!empty($assign)){
                $ref_expr = $assign->expr;
                return $this->traceExpressionVariables($ref_expr);
            }else{
                return $details_ret;
            }
            
        }else{
            return $details_ret;
        }
    }
    
    private function isFunctionParameter(Variable $var){
        $name = $var->name;
        $filter = function($item) use ($name){
            return ($item->name == $name);
        };
        return !empty(array_filter($this->params, $filter));
    }
    
    private function constructVariableDetails($var, $taint = Annotation::UNASSIGNED, $sanitising = array()){
        return array(self::VARIABLE_KEY => $var,
                    self::TAINT_KEY => $taint,
                    self::SANITISATION_KEY => $sanitising);
    }
    
    private function getVariableDetails(Variable $var){
        $name = $var->name;
        if(array_key_exists($name, $this->variables)){
            return $this->variables[$name];
        }else if($name instanceof Expr){
            $unresolved_vars = $this->variables[self::UNRESOLVED_VARIABLE_KEY];
            $filter_matching = function($item) use ($var){
                return $item[self::VARIABLE_KEY] == $var;
            };
            $filter_res = array_filter($unresolved_vars, $filter_matching);
            if(!empty($filter_res)){
                return $filter_res;
            }else{
                $var_arr = $this->constructVariableDetails($var, Annotation::UNKNOWN);
                $unresolved_vars[] = $var_arr;
                return $var_arr;
            }            
        }else{
            if(array_key_exists($name, $this->variables)){
                return $this->variables[$name];
            }else{
                $assign = $var->environment->resolveVariable($name);
                $var_arr = $this->constructVariableDetails($var);
                $var_arr[self::VARIABLE_DEF] = $assign;
                $this->variables[$name] = $var_arr;
                return $var_arr;
            }
        }
    }
    
    private function getReturnStatements($stmts){
        $filter_returns = function($item){
            return ($item instanceof Node\Stmt\Return_);
        };
        return array_filter($stmts, $filter_returns);
    }
    
}
