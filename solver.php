<?php

/*
 * Prolog interpreter - Inference Engine
 */

require_once(__DIR__ . '/class_ok.php');

function execute($rules, $query)
{
    $obj = new Interpreter();
    return $obj->execute($rules, $query);
}

class Interpreter
{

    public function execute($rules, $query, $show = false)
    {

        if ($show)
            echo ("Parsing rulesets.\n");

        $rules = explode("\n", $rules);
        $outr = array();
        $outi = 0;
        foreach ($rules as $rule) {
            if (!strlen($rule) || ($rule[0] == "#"))
                continue;
            $parsedRule = $this->ParseRule(new Tokeniser($rule));
            if ($parsedRule == null)
                continue;
            $outr[] = $parsedRule;
            // print ("Rule "+outi+" is : ");
            if ($show)
                echo $parsedRule . "\n";
        }

        if ($show)
            echo ("\nAttaching builtins to database.\n");
        $outr['builtin'] = array(
            "compare/3" => 'Comparitor',
            "cut/0" => 'Cut',
            "call/1" => 'Call',
            "fail/0" => 'Fail',
            "bagof/3" => 'BagOf',
            "external/3" => 'External'
        );

        if ($show)
            echo ("Attachments done.\n");

        if ($show)
            echo ("\nParsing query.\n");
        $q = $this->ParseBody(new Tokeniser($query));
        if ($q == null) {
            echo ("An error occurred parsing the query.\n");
            return;
        }
        $q = new Body($q);
        if ($show) {
            echo ("Query is: ");
            echo $q;
            echo ("\n\n");
        }

        $vs = array_values($this->varNames($q->list));
        $pile = new ReportStack($this);
        // Prove the query.
        $this->prove($this->renameVariables($q->list, 0, array()), array(), $outr, 1, $this->applyOne(array($pile, 'log'), $vs));

        return $pile;
    }

// Go through a list of terms (ie, a Body or Partlist's list) renaming variables
// by appending 'level' to each variable name.
// How non-graph-theoretical can this get?!?
// "parent" points to the subgoal, the expansion of which lead to these terms.
    function renameVariables($list, $level, $parent)
    {
        $out = array();

        if ($list instanceof Atom) {
            return $list;
        } else if ($list instanceof Variable) {
            return new Variable($list->name . "." . $level);
        } else if ($list instanceof Term) {
            $out = new Term($list->name, $this->renameVariables($list->partlist->list, $level, $parent));
            $out->parent = $parent;
            return $out;
        }

        foreach ($list as $i => $item) {
            $out[$i] = $this->renameVariables($list[$i], $level, $parent);
        }

        return $out;
    }

// Functional programming bits... Currying and suchlike
    function applyOne($f, $arg1)
    {
        return function ($arg2) use ($f, $arg1) {
                    return call_user_func_array($f, array($arg1, $arg2));
                };
    }

    function ParseRule($tk)
    {
        // A rule is a Head followed by . or by :- Body
        $h = $this->ParseHead($tk);
        if (!$h)
            return null;

        if ($tk->current == ".") {
            // A simple rule.
            return new Rule($h);
        }

        if ($tk->current != ":-")
            return null;
        $tk->consume();
        $b = $this->ParseBody($tk);

        if ($tk->current != ".")
            return null;

        return new Rule($h, $b);
    }

    function ParseHead($tk)
    {
        // A head is simply a term. (errors cascade back up)
        return $this->ParseTerm($tk);
    }

    function ParseBody($tk)
    {
        // Body -> Term {, Term...}

        $p = array();
        $i = 0;

        while (($t = $this->ParseTerm($tk)) != null) {
            $p[$i++] = $t;
            if ($tk->current != ",")
                break;
            $tk->consume();
        }

        if ($i == 0)
            return null;
        return $p;
    }

    function ParseTerm($tk)
    {
        // Term -> [NOTTHIS] id ( optParamList )

        if ($tk->type == "punc" && $tk->current == "!") {
            // Parse ! as cut/0
            $tk->consume();
            return new Term("cut", array());
        }

        if ($tk->type != "id")
            return null;
        $name = $tk->current;
        $tk->consume();

        if ($tk->current != "(") {
            // fail shorthand for fail(), ie, fail/0
            if ($name == "fail") {
                return new Term($name, array());
            }
            return null;
        }
        $tk->consume();

        $p = array();
        $i = 0;
        while ($tk->current != ")") {
            if ($tk->type == "eof")
                return null;

            $part = $this->ParsePart($tk);
            if ($part == null)
                return null;

            if ($tk->current == ",")
                $tk->consume();
            else if ($tk->current != ")")
                return null;

            // Add the current Part onto the list...
            $p[$i++] = $part;
        }
        $tk->consume();

        $term = new Term($name, $p);

        return $term;
    }

// This was a beautiful piece of code. It got kludged to add [a,b,c|Z] sugar.
    function ParsePart($tk)
    {
        // Part -> var | id | id(optParamList)
        // Part -> [ listBit ] ::-> cons(...)
        if ($tk->type == "var") {
            $n = $tk->current;
            $tk->consume();
            return new Variable($n);
        }

        if ($tk->type != "id") {
            if ($tk->type != "punc" || $tk->current != "[")
                return null;
            // Parse a list (syntactic sugar goes here)
            $tk->consume();
            // Special case: [] = new atom(nil).
            if ($tk->type == "punc" && $tk->current == "]") {
                $tk->consume();
                return new Atom("nil");
            }

            // Get a list of parts into l
            $l = array();
            $i = 0;

            while (true) {
                $t = $this->ParsePart($tk);
                if ($t == null)
                    return null;

                $l[$i++] = $t;
                if ($tk->current != ",")
                    break;
                $tk->consume();
            }

            // Find the end of the list ... "| Var ]" or "]".
            if ($tk->current == "|") {
                $tk->consume();
                if ($tk->type != "var")
                    return null;
                $append = new Variable($tk->current);
                $tk->consume();
            } else {
                $append = new Atom("nil");
            }
            if ($tk->current != "]")
                return null;
            $tk->consume();
            // Return the new cons.... of all this rubbish.
            for
            (
            --$i
            ; $i >= 0; $i--)
                $append = new Term("cons", array($l[$i], $append));
            return $append;
        }

        $name = $tk->current;
        $tk->consume();

        if ($tk->current != "(")
            return new Atom($name);
        $tk->consume();

        $p = array();
        $i = 0;
        while ($tk->current != ")") {
            if ($tk->type == "eof")
                return null;

            $part = $this->ParsePart($tk);
            if ($part == null)
                return null;

            if ($tk->current == ",")
                $tk->consume();
            else if ($tk->current != ")")
                return null;

            // Add the current Part onto the list...
            $p[$i++] = $part;
        }
        $tk->consume();

        return new Term($name, $p);
    }

    function varNames($list)
    {
        $out = array();

        foreach ($list as $item) {
            switch ($item->type) {
                case 'Variable' :
                    $out[$item->name] = $item;
                    break;

                case 'Term' :
                    $out2 = $this->varNames($item->partlist->list);
                    $out = array_merge($out, $out2);
                    break;
            }
        }

        return $out;
    }

// The main proving engine. Returns: null (keep going), other (drop out)
    function prove($goalList, $environment, $db, $level, $reportFunction)
    {
        //DEBUG: print ("in main prove...\n");
        if (count($goalList) == 0) {
            call_user_func($reportFunction, $environment);

            //if (!more) return "done";
            return null;
        }

        // Prove the first term in the goallist. We do this by trying to
        // unify that term with the rules in our database. For each
        // matching rule, replace the term with the body of the matching
        // rule, with appropriate substitutions.
        // Then prove the new goallist. (recursive call)

        $thisTerm = $goalList[0];
        //print ("Debug: thisterm = "); thisTerm.print(); print("\n");
        // Do we have a builtin?
        $signature = $thisTerm->name . "/" . count($thisTerm->partlist->list);
        // print ("Debug: searching for builtin "+thisTerm.name+"/"+thisTerm.partlist.list.length+"\n");
        if (array_key_exists($signature, $db['builtin'])) {
            $builtin = $db['builtin'][$signature];
            //print ("builtin with name " + thisTerm.name + " found; calling prove() on it...\n");
            // Stick the new body list
            $newGoals = array();
            for ($j = 1; $j < count($goalList); $j++)
                $newGoals[$j - 1] = $goalList[$j];
            return call_user_func_array(array($this, $builtin), array($thisTerm, $newGoals, $environment, $db, $level + 1, $reportFunction));
        }

        foreach ($db as $i => $item) {
            if ($i === 'builtin')
                continue;

            $rule = $db[$i];

            // We'll need better unification to allow the 2nd-order
            // rule matching ... later.
            if ($rule->head->name != $thisTerm->name)
                continue;

            // Rename the variables in the head and body
            $renamedHead = new Term($rule->head->name, $this->renameVariables($rule->head->partlist->list, $level, $thisTerm));
            // renamedHead.ruleNumber = i;

            $env2 = $this->unify($thisTerm, $renamedHead, $environment);
            if ($env2 === null)
                continue;

            $body = $rule->body;
            if ($body != null) {
                $newFirstGoals = $this->renameVariables($rule->body->list, $level, $renamedHead);
                // Stick the new body list
                $newGoals = array();
                for ($j = 0; $j < count($newFirstGoals); $j++) {
                    $newGoals[$j] = $newFirstGoals[$j];
                }
                for ($k = 1; $k < count($goalList); $k++)
                    $newGoals[$j++] = $goalList[$k];
                $ret = $this->prove($newGoals, $env2, $db, $level + 1, $reportFunction);
                if ($ret != null)
                    return $ret;
            } else {
                // Just prove the rest of the goallist, recursively.
                $newGoals = array();
                for ($j = 1; $j < count($goalList); $j++)
                    $newGoals[$j - 1] = $goalList[$j];
                $ret = $this->prove($newGoals, $env2, $db, $level + 1, $reportFunction);
                if ($ret != null)
                    return $ret;
            }

            if ($renamedHead->cut) {
                //print ("Debug: this goal "); thisTerm.print(); print(" has been cut.\n");
                break;
            }
            if ($thisTerm->parent && $thisTerm->parent->cut) {
                //print ("Debug: parent goal "); thisTerm.parent.print(); print(" has been cut.\n");
                break;
            }
        }

        return null;
    }

// Unify two terms in the current environment. Returns a new environment.
// On failure, returns null.
    function unify($x, $y, $env)
    {
        $x = $this->value($x, $env);
        $y = $this->value($y, $env);
        if ($x->type == "Variable")
            return $this->newEnv($x->name, $y, $env);
        if ($y->type == "Variable")
            return $this->newEnv($y->name, $x, $env);
        if (($x->type == "Atom") || ($y->type == "Atom")) {
            if (($x->type == $y->type) && ($x->name == $y->name)) {
                return $env;
            } else {
                return null;
            }
        }
        // x.type == y.type == Term...
        if ($x->name != $y->name)
            return null; // Ooh, so first-order.
        if (count($x->partlist->list) != count($y->partlist->list))
            return null;

        for ($i = 0; $i < count($x->partlist->list); $i++) {
            $env = $this->unify($x->partlist->list[$i], $y->partlist->list[$i], $env);
            if ($env === null)
                return null;
        }

        return $env;
    }

// The value of x in a given environment
    function value($x, $env)
    {
        if ($x->type == "Term") {
            $l = array();
            for ($i = 0; $i < count($x->partlist->list); $i++) {
                $l[$i] = $this->value($x->partlist->list[$i], $env);
            }
            return new Term($x->name, $l);
        }
        if ($x->type != "Variable")
            return $x;  // We only need to check the values of variables...

        $binding = null;
        if (array_key_exists($x->name, $env))
            $binding = $env[$x->name];

        if ($binding == null)
            return $x;  // Just the variable, no binding.
        return $this->value($binding, $env);
    }

// Give a new environment from the old with "n" (a string variable name) bound to "z" (a part)
// Part is Atom|Term|Variable
    function newEnv($n, $z, $e)
    {
        // We assume that n has been 'unwound' or 'followed' as far as possible
        // in the environment. If this is not the case, we could get an alias loop.
        $ne = array();
        $ne[$n] = $z;
        foreach ($e as $i => $val)
            if ($i != $n)
                $ne[$i] = $e[$i];

        return $ne;
    }
  
// A sample builtin function, including all the bits you need to get it to work
// within the general proving mechanism.
// compare(First, Second, CmpValue)
// First, Second must be bound to strings here.
// CmpValue is bound to -1, 0, 1
    function Comparitor($thisTerm, $goalList, $environment, $db, $level, $reportFunction)
    {
        //DEBUG print ("in Comparitor.prove()...\n");
        // Prove the builtin bit, then break out and prove
        // the remaining goalList.
        // if we were intending to have a resumable builtin (one that can return
        // multiple bindings) then we'd wrap all of this in a while() loop.
        // Rename the variables in the head and body
        // var renamedHead = new Term(rule.head.name, renameVariables(rule.head.partlist.list, level));

        $first = $this->value($thisTerm->partlist->list[0], $environment);
        if ($first->type != "Atom") {
            //print("Debug: Comparitor needs First bound to an Atom, failing\n");
            return null;
        }

        $second = $this->value($thisTerm->partlist->list[1], $environment);
        if ($second->type != "Atom") {
            //print("Debug: Comparitor needs Second bound to an Atom, failing\n");
            return null;
        }

        $cmp = "eq";
        if ($first->name < $second->name)
            $cmp = "lt";
        else if ($first->name > $second->name)
            $cmp = "gt";

        $env2 = $this->unify($thisTerm->partlist->list[2], new Atom($cmp), $environment);

        if ($env2 == null) {
            //print("Debug: Comparitor cannot unify CmpValue with " + cmp + ", failing\n");
            return null;
        }

        // Just prove the rest of the goallist, recursively.
        return $this->prove($goalList, $env2, $db, $level + 1, $reportFunction);
    }

    function Cut($thisTerm, $goalList, $environment, $db, $level, $reportFunction)
    {
        //DEBUG print ("in Comparitor.prove()...\n");
        // Prove the builtin bit, then break out and prove
        // the remaining goalList.
        // if we were intending to have a resumable builtin (one that can return
        // multiple bindings) then we'd wrap all of this in a while() loop.
        // Rename the variables in the head and body
        // var renamedHead = new Term(rule.head.name, renameVariables(rule.head.partlist.list, level));
        // On the way through, we do nothing...
        // Just prove the rest of the goallist, recursively.
        $ret = $this->prove($goalList, $environment, $db, $level + 1, $reportFunction);

        // Backtracking through the 'cut' stops any further attempts to prove this subgoal.
        //print ("Debug: backtracking through cut/0: thisTerm.parent = "); thisTerm.parent.print(); print("\n");
        $thisTerm->parent->cut = true;

        return $ret;
    }

// Given a single argument, it sticks it on the goal list.
    function Call($thisTerm, $goalList, $environment, $db, $level, $reportFunction)
    {
        // Prove the builtin bit, then break out and prove
        // the remaining goalList.
        // Rename the variables in the head and body
        // var renamedHead = new Term(rule.head.name, renameVariables(rule.head.partlist.list, level));

        $first = $this->value($thisTerm->partlist->list[0], $environment);
        if ($first->type != "Term") {
            //print("Debug: Call needs parameter bound to a Term, failing\n");
            return null;
        }

        //var newGoal = new Term(first.name, renameVariables(first.partlist.list, level, thisTerm));
        //newGoal.parent = thisTerm;
        // Stick this as a new goal on the start of the goallist
        $newGoals = array();
        $newGoals[0] = $first;
        $first->parent = $thisTerm;

        for ($j = 0; $j < count($goalList); $j++)
            $newGoals[$j + 1] = $goalList[$j];

        // Just prove the rest of the goallist, recursively.
        return $this->prove($newGoals, $environment, $db, $level + 1, $reportFunction);
    }

    function Fail($thisTerm, $goalList, $environment, $db, $level, $reportFunction)
    {
        return null;
    }

    function BagOf($thisTerm, $goalList, $environment, $db, $level, $reportFunction)
    {
        // bagof(Term, ConditionTerm, ReturnList)

        $collect = $this->value($thisTerm->partlist->list[0], $environment);
        $subgoal = $this->value($thisTerm->partlist->list[1], $environment);
        $into = $this->value($thisTerm->partlist->list[2], $environment);

        $collect = $this->renameVariables($collect, $level, $thisTerm);
        $newGoal = new Term($subgoal->name, $this->renameVariables($subgoal->partlist->list, $level, $thisTerm));
        $newGoal->parent = $thisTerm;

        $newGoals = array();
        $newGoals[0] = $newGoal;

        // Prove this subgoal, collecting up the environments...
        $anslist = new stdClass();  // TODO : this object sux
        $anslist->list = array();
        $anslist->renumber = -1;
        $ret = $this->prove($newGoals, $environment, $db, $level + 1, $this->BagOfCollectFunction($collect, $anslist));

        // Turn anslist into a proper list and unify with 'into'
        // optional here: nil anslist -> fail?
        $answers = new Atom("nil");

        for ($i = count($anslist->list); $i > 0; $i--)
            $answers = new Term("cons", array($anslist->list[$i - 1], $answers));

        //print("Debug: unifying "); into.print(); print(" with "); answers.print(); print("\n");
        $env2 = $this->unify($into, $answers, $environment);

        if ($env2 == null) {
            //print("Debug: bagof cannot unify anslist with "); into.print(); print(", failing\n");
            return null;
        }

        // Just prove the rest of the goallist, recursively.
        return $this->prove($goalList, $env2, $db, $level + 1, $reportFunction);
    }

// Aux function: return the reportFunction to use with a bagof subgoal
    function BagOfCollectFunction($collect, $anslist)
    {
        $zis = $this;
        return function($env) use ($collect, $anslist, $zis) {
                    // Rename this appropriately and throw it into anslist
                    $anslist->list[count($anslist->list)] = $zis->renameVariables($zis->value($collect, $env), $anslist->renumber--, array());
                };
    }

// Call out to external javascript
// external/3 takes three arguments:
// first: a template string that uses $1, $2, etc. as placeholders for

    function External($thisTerm, $goalList, $environment, $db, $level, $reportFunction)
    {
        //print ("DEBUG: in External...\n");
        // Get the first term, the template.
        $first = $this->value($thisTerm->partlist->list[0], $environment);
        if ($first->type != "Atom") {
            //print("Debug: External needs First bound to a string Atom, failing\n");
            return null;
        }

        if (!preg_match('#^"(.*)"$#', $first->name, $r))
            return null;
        $r = $r[1];

        //print("DEBUG: template for External/3 is "+r+"\n");
        // Get the second term, the argument list.
        $second = $this->value($thisTerm->partlist->list[1], $environment);
        $arglist = array();
        $i = 1;
        while (($second->type == "Term") && ($second->name == "cons")) {
            // Go through second an argument at a time...
            $arg = $this->value($second->partlist->list[0], $environment);
            if ($arg->type != "Atom") {
                //print("DEBUG: External/3: argument "+i+" must be an Atom, not "); arg.print(); print("\n");
                return null;
            }
            $r = str_replace('$' . $i, $arg->name, $r);
            //print("DEBUG: External/3: RegExp is "+re+", arg is "+arg.name+"\n");
            //print("DEBUG: External/3: r becomes "+r+"\n");
            $second = $second->partlist->list[1];
            $i++;
        }
        if ($second->type != "Atom" || $second->name != "nil") {
            //print("DEBUG: External/3 needs second to be a list, not "); second.print(); print("\n");
            return null;
        }

        //print("DEBUG: External/3 about to eval \""+r+"\"\n");

        $ret = null;
        $checkEval = eval('$ret = ' . $r . ';');
        if (false === $checkEval) {
            echo 'Cannot evaluate ' . $r . + "\n";
            $ret = 'nil';
        }

        //print("DEBUG: External/3 got "+ret+" back\n");
        // Convert back into an atom...
        $env2 = $this->unify($thisTerm->partlist->list[2], new Atom($ret), $environment);

        if ($env2 == null) {
            //print("Debug: External/3 cannot unify OutValue with " + ret + ", failing\n");
            return null;
        }

        // Just prove the rest of the goallist, recursively.
        return $this->prove($goalList, $env2, $db, $level + 1, $reportFunction);
    }

}