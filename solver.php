<?php

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

function execute($rules, $query, $show = false) {

    if ($show)
        echo ("Parsing rulesets.\n");

    $rules = explode("\n", $rules);
    $outr = array();
    $outi = 0;
    foreach ($rules as $rule) {
        if (!strlen($rule) || ($rule[0] == "#"))
            continue;
        $parsedRule = ParseRule(new Tokeniser($rule));
        if ($parsedRule == null)
            continue;
        $outr[] = $parsedRule;
        // print ("Rule "+outi+" is : ");
        if ($show)
            $parsedRule->dump();
    }

    if ($show)
        echo ("\nAttaching builtins to database.\n");
    $outr['builtin'] = array(
        "compare/3" => 'Comparitor',
        "cut/0" => 'Cut',
        "call/1" => 'Call',
        "fail/0" => 'Fail',
        "bagof/3" => 'BagOf',
        "external/3" => 'External',
        "external2/3" => 'ExternalAndParse'
    );

    if ($show)
        echo ("Attachments done.\n");

    if ($show)
        echo ("\nParsing query.\n");
    $q = ParseBody(new Tokeniser($query));
    if ($q == null) {
        echo ("An error occurred parsing the query.\n");
        return;
    }
    $q = new Body($q);
    if ($show) {
        echo ("Query is: ");
        $q->dump();
        echo ("\n\n");
    }

    $vs = array_values(varNames($q->list));
    //$pile = new ReportStack();
    // Prove the query.
    prove(renameVariables($q->list, 0, array()), array(), $outr, 1, applyOne('printVars', $vs));
    //$pile->dump();
    //return $pile;
}

// Go through a list of terms (ie, a Body or Partlist's list) renaming variables
// by appending 'level' to each variable name.
// How non-graph-theoretical can this get?!?
// "parent" points to the subgoal, the expansion of which lead to these terms.
function renameVariables($list, $level, $parent) {
    $out = array();

    if ($list instanceof Atom) {
        return $list;
    } else if ($list instanceof Variable) {
        return new Variable($list->name . "." . $level);
    } else if ($list instanceof Term) {
        $out = new Term($list->name, renameVariables($list->partlist->list, $level, $parent));
        $out->parent = $parent;
        return $out;
    }

    foreach ($list as $i => $item) {
        $out[$i] = renameVariables($list[$i], $level, $parent);
        /*
          if (list[i].type == "Atom") {
          out[i] = list[i];
          } else if (list[i].type == "Variable") {
          out[i] = new Variable(list[i].name + "." + level);
          } else if (list[i].type == "Term") {
          (out[i] = new Term(list[i].name, renameVariables(list[i].partlist.list, level, parent))).parent = parent;
          }
         */
    }

    return $out;
}

// Functional programming bits... Currying and suchlike
function applyOne($f, $arg1) {
    return function ($arg2) use ($f, $arg1) {
                return call_user_func_array($f, array($arg1, $arg2));
            };
}

function ParseRule($tk) {
    // A rule is a Head followed by . or by :- Body
    $h = ParseHead($tk);
    if (!$h)
        return null;

    if ($tk->current == ".") {
        // A simple rule.
        return new Rule($h);
    }

    if ($tk->current != ":-")
        return null;
    $tk->consume();
    $b = ParseBody($tk);

    if ($tk->current != ".")
        return null;

    return new Rule($h, $b);
}

function ParseHead($tk) {
    // A head is simply a term. (errors cascade back up)
    return ParseTerm($tk);
}

function ParseBody($tk) {
    // Body -> Term {, Term...}

    $p = array();
    $i = 0;

    while (($t = ParseTerm($tk)) != null) {
        $p[$i++] = $t;
        if ($tk->current != ",")
            break;
        $tk->consume();
    }

    if ($i == 0)
        return null;
    return $p;
}

function ParseTerm($tk) {
    // Term -> [NOTTHIS] id ( optParamList )

    if ($tk->type == "punc" && $tk->current == "!") {
        // Parse ! as cut/0
        $tk->consume();
        return new Term("cut", array());
    }

    $notthis = false;
    if ($tk->current == "NOTTHIS") {
        $notthis = true;
        $tk->consume();
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

        $part = ParsePart($tk);
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
    if ($notthis)
        $term->excludeThis = true;
    return $term;
}

// This was a beautiful piece of code. It got kludged to add [a,b,c|Z] sugar.
function ParsePart($tk) {
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
            $t = ParsePart($tk);
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

        $part = ParsePart($tk);
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

class Atom {

    public $name;
    public $type;

    public function __construct($head) {
        $this->name = $head;
        $this->type = "Atom";
    }

    public function dump() {
        echo $this->name;
    }

    public function __toString() {
        return $this->name;
    }

}

class Variable {

    public $name;
    public $type;

    public function __construct($head) {
        $this->name = $head;
        $this->type = "Variable";
    }

    public function dump() {
        echo $this->name;
    }

    public function __toString() {
        return $this->name;
    }

}

class Tokeniser {

    public $remainder;
    public $current;

    // The Tiny-Prolog parser goes here.
    public function __construct($string) {
        $this->remainder = $string;
        $this->current = null;
        $this->type = null; // "eof", "id", "var", "punc" etc.
        $this->consume(); // Load up the first token.
    }

    public function consume() {
        if ($this->type == "eof")
            return;
        // Eat any leading WS
        if (preg_match('#^\s*(.*)$#', $this->remainder, $r)) {
            $this->remainder = $r[1];
        }

        if ($this->remainder == "") {
            $this->current = null;
            $this->type = "eof";
            return;
        }

        if (preg_match('#^([\(\)\.,\[\]\|\!]|\:\-)(.*)$#', $this->remainder, $r)) {
            $this->remainder = $r[2];
            $this->current = $r[1];
            $this->type = "punc";
            return;
        }

        if (preg_match('#^([A-Z_][a-zA-Z0-9_]*)(.*)$#', $this->remainder, $r)) {
            $this->remainder = $r[2];
            $this->current = $r[1];
            $this->type = "var";
            return;
        }

        // URLs in curly-bracket pairs
        if (preg_match('#^(\{[^\}]*\})(.*)$#', $this->remainder, $r)) {
            $this->remainder = $r[2];
            $this->current = $r[1];
            $this->type = "id";
            return;
        }

        // Quoted strings
        if (preg_match('#^("[^"]*")(.*)$#', $this->remainder, $r)) {
            $this->remainder = $r[2];
            $this->current = $r[1];
            $this->type = "id";
            return;
        }

        if (preg_match('#^([a-zA-Z0-9][a-zA-Z0-9_]*)(.*)$#', $this->remainder, $r)) {
            $this->remainder = $r[2];
            $this->current = $r[1];
            $this->type = "id";
            return;
        }

        if (preg_match('#^(-[0-9][0-9]*)(.*)$#', $this->remainder, $r)) {
            $this->remainder = $r[2];
            $this->current = $r[1];
            $this->type = "id";
            return;
        }

        $this->current = null;
        $this->type = "eof";
    }

}

class Term {

    public $name;
    public $type;
    public $partlist;

    public function __construct($head, $list) {
        $this->name = $head;
        $this->partlist = new Partlist($list);
        $this->type = "Term";
    }

    public function dump() {
        if ($this->name == "cons") {
            $x = $this;
            while ($x->type == "Term" && $x->name == "cons" && count($x->partlist->list) == 2) {
                $x = $x->partlist->list[1];
            }
            if (($x->type == "Atom" && $x->name == "nil") || $x->type == "Variable") {
                $x = $this;
                echo ("[");
                $com = false;
                while ($x->type == "Term" && $x->name == "cons" && count($x->partlist->list) == 2) {
                    if ($com)
                        echo (", ");
                    $x->partlist->list[0]->dump();
                    $com = true;
                    $x = $x->partlist->list[1];
                }
                if ($x->type == "Variable") {
                    echo (" | ");
                    $x->dump();
                }
                echo ("]");
                return;
            }
        }
        echo $this->name . "(";
        $this->partlist->dump();
        echo (")");
    }

    public function __toString() {
        $retour = '';
        if ($this->name == "cons") {
            $x = $this;
            while ($x->type == "Term" && $x->name == "cons" && count($x->partlist->list) == 2) {
                $x = $x->partlist->list[1];
            }
            if (($x->type == "Atom" && $x->name == "nil") || $x->type == "Variable") {
                $x = $this;
                $retour .= "[";
                $com = false;
                while ($x->type == "Term" && $x->name == "cons" && count($x->partlist->list) == 2) {
                    if ($com)
                        $retour .= ", ";
                    $retour .= (string) $x->partlist->list[0];
                    $com = true;
                    $x = $x->partlist->list[1];
                }
                if ($x->type == "Variable") {
                    $retour .= " | ";
                    $retour .= (string) $x;
                }
                $retour .= "]";
                return $retour;
            }
        }
        $retour .= $this->name . "(";
        $retour .= (string) $this->partlist;
        $retour .= ")";
        return $retour;
    }

}

class Partlist {

    public $list;

    public function __construct($list) {
        $this->list = $list;
    }

    public function dump() {
        for ($i = 0; $i < count($this->list); $i++) {
            $this->list[$i]->dump();
            if ($i < count($this->list) - 1)
                print (", ");
        }
    }

    public function __toString() {
        $retour = array();
        foreach ($this->list as $item)
            $retour[] = (string) $item;
        return implode(', ', $retour);
    }

}

class Rule {

    public $head;
    public $body;

    public function __construct($head, $bodylist = null) {
        $this->head = $head;
        if ($bodylist != null)
            $this->body = new Body($bodylist);
        else
            $this->body = null;
    }

    public function dump() {
        if ($this->body == null) {
            $this->head->dump();
            echo (".\n");
        } else {
            $this->head->dump();
            print (" :- ");
            $this->body->dump();
            print (".\n");
        }
    }

    public function __toString() {
        if ($this->body == null) {
            return ((string) $this->head) . ".";
        } else {
            return sprintf("%s :- %s.", $this->head, $this->body);
        }
    }

}

class Body {

    public $list;

    public function __construct($list) {
        $this->list = $list;
    }

    public function dump() {
        for ($i = 0; $i < count($this->list); $i++) {
            $this->list[$i]->dump();
            if ($i < count($this->list) - 1)
                echo (", ");
        }
    }

    public function __toString() {
        $retour = array();
        foreach ($this->list as $item)
            $retour[] = (string) $item;
        return implode(', ', $retour);
    }

}

function varNames($list) {
    $out = array();

    foreach ($list as $item) {
        switch ($item->type) {
            case 'Variable' :
                $out[$item->name] = $item;
                break;

            case 'Term' :
                $out2 = varNames($item->partlist->list);
                $out = array_merge($out, $out2);
                break;
        }
    }

    return $out;
}

// The main proving engine. Returns: null (keep going), other (drop out)
function prove($goalList, $environment, $db, $level, $reportFunction) {
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
        return $builtin($thisTerm, $newGoals, $environment, $db, $level + 1, $reportFunction);
    }

    foreach ($db as $i => $item) {
        if ($i === 'builtin')
            continue;

        //print ("Debug: in rule selection. thisTerm = "); thisTerm.print(); print ("\n");
        if (property_exists($thisTerm, 'excludeRule') && $thisTerm->excludeRule === $i) {
            // print("DEBUG: excluding rule number $i in attempt to satisfy "); $thisTerm->dump(); print("\n");
            continue;
        }

        $rule = $db[$i];

        // We'll need better unification to allow the 2nd-order
        // rule matching ... later.
        if ($rule->head->name != $thisTerm->name)
            continue;

        // Rename the variables in the head and body
        $renamedHead = new Term($rule->head->name, renameVariables($rule->head->partlist->list, $level, $thisTerm));
        // renamedHead.ruleNumber = i;

        $env2 = unify($thisTerm, $renamedHead, $environment);
        if ($env2 === null)
            continue;

        $body = $rule->body;
        if ($body != null) {
            $newFirstGoals = renameVariables($rule->body->list, $level, $renamedHead);
            // Stick the new body list
            $newGoals = array();
            for ($j = 0; $j < count($newFirstGoals); $j++) {
                $newGoals[$j] = $newFirstGoals[$j];
                if ($rule->body->list[$j]->excludeThis)
                    $newGoals[$j]->excludeRule = $i;
            }
            for ($k = 1; $k < count($goalList); $k++)
                $newGoals[$j++] = $goalList[$k];
            $ret = prove($newGoals, $env2, $db, $level + 1, $reportFunction);
            if ($ret != null)
                return $ret;
        } else {
            // Just prove the rest of the goallist, recursively.
            $newGoals = array();
            for ($j = 1; $j < count($goalList); $j++)
                $newGoals[$j - 1] = $goalList[$j];
            $ret = prove($newGoals, $env2, $db, $level + 1, $reportFunction);
            if ($ret != null)
                return $ret;
        }

        if ($renamedHead->cut) {
            //print ("Debug: this goal "); thisTerm.print(); print(" has been cut.\n");
            break;
        }
        if ($thisTerm->parent->cut) {
            //print ("Debug: parent goal "); thisTerm.parent.print(); print(" has been cut.\n");
            break;
        }
    }

    return null;
}

// Unify two terms in the current environment. Returns a new environment.
// On failure, returns null.
function unify($x, $y, $env) {
    $x = value($x, $env);
    $y = value($y, $env);
    if ($x->type == "Variable")
        return newEnv($x->name, $y, $env);
    if ($y->type == "Variable")
        return newEnv($y->name, $x, $env);
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
        $env = unify($x->partlist->list[$i], $y->partlist->list[$i], $env);
        if ($env === null)
            return null;
    }

    return $env;
}

// The value of x in a given environment
function value($x, $env) {
    if ($x->type == "Term") {
        $l = array();
        for ($i = 0; $i < count($x->partlist->list); $i++) {
            $l[$i] = value($x->partlist->list[$i], $env);
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
    return value($binding, $env);
}

// Give a new environment from the old with "n" (a string variable name) bound to "z" (a part)
// Part is Atom|Term|Variable
function newEnv($n, $z, $e) {
    // We assume that n has been 'unwound' or 'followed' as far as possible
    // in the environment. If this is not the case, we could get an alias loop.
    $ne = array();
    $ne[$n] = $z;
    foreach ($e as $i => $val)
        if ($i != $n)
            $ne[$i] = $e[$i];

    return $ne;
}

function printVars($which, $environment) {
    // Print bindings.
    if (count($which) == 0) {
        echo ("true\n");
    } else {
        for ($i = 0; $i < count($which); $i++) {
            echo ($which[$i]->name);
            echo (" = ");
            $obj = value(new Variable($which[$i]->name . ".0"), $environment);
            $obj->dump();
            echo ("\n");
        }
    }
    echo ("\n");
}

// A sample builtin function, including all the bits you need to get it to work
// within the general proving mechanism.
// compare(First, Second, CmpValue)
// First, Second must be bound to strings here.
// CmpValue is bound to -1, 0, 1
function Comparitor($thisTerm, $goalList, $environment, $db, $level, $reportFunction) {
    //DEBUG print ("in Comparitor.prove()...\n");
    // Prove the builtin bit, then break out and prove
    // the remaining goalList.
    // if we were intending to have a resumable builtin (one that can return
    // multiple bindings) then we'd wrap all of this in a while() loop.
    // Rename the variables in the head and body
    // var renamedHead = new Term(rule.head.name, renameVariables(rule.head.partlist.list, level));

    $first = value($thisTerm->partlist->list[0], $environment);
    if ($first->type != "Atom") {
        //print("Debug: Comparitor needs First bound to an Atom, failing\n");
        return null;
    }

    $second = value($thisTerm->partlist->list[1], $environment);
    if ($second->type != "Atom") {
        //print("Debug: Comparitor needs Second bound to an Atom, failing\n");
        return null;
    }

    $cmp = "eq";
    if ($first->name < $second->name)
        $cmp = "lt";
    else if ($first->name > $second->name)
        $cmp = "gt";

    $env2 = unify($thisTerm->partlist->list[2], new Atom($cmp), $environment);

    if ($env2 == null) {
        //print("Debug: Comparitor cannot unify CmpValue with " + cmp + ", failing\n");
        return null;
    }

    // Just prove the rest of the goallist, recursively.
    return prove($goalList, $env2, $db, $level + 1, $reportFunction);
}

function Cut($thisTerm, $goalList, $environment, $db, $level, $reportFunction) {
    //DEBUG print ("in Comparitor.prove()...\n");
    // Prove the builtin bit, then break out and prove
    // the remaining goalList.
    // if we were intending to have a resumable builtin (one that can return
    // multiple bindings) then we'd wrap all of this in a while() loop.
    // Rename the variables in the head and body
    // var renamedHead = new Term(rule.head.name, renameVariables(rule.head.partlist.list, level));
    // On the way through, we do nothing...
    // Just prove the rest of the goallist, recursively.
    $ret = prove($goalList, $environment, $db, $level + 1, $reportFunction);

    // Backtracking through the 'cut' stops any further attempts to prove this subgoal.
    //print ("Debug: backtracking through cut/0: thisTerm.parent = "); thisTerm.parent.print(); print("\n");
    $thisTerm->parent->cut = true;

    return $ret;
}

// Given a single argument, it sticks it on the goal list.
function Call($thisTerm, $goalList, $environment, $db, $level, $reportFunction) {
    // Prove the builtin bit, then break out and prove
    // the remaining goalList.
    // Rename the variables in the head and body
    // var renamedHead = new Term(rule.head.name, renameVariables(rule.head.partlist.list, level));

    $first = value($thisTerm->partlist->list[0], $environment);
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
    return prove($newGoals, $environment, $db, $level + 1, $reportFunction);
}

function Fail($thisTerm, $goalList, $environment, $db, $level, $reportFunction) {
    return null;
}

function BagOf($thisTerm, $goalList, $environment, $db, $level, $reportFunction) {
    // bagof(Term, ConditionTerm, ReturnList)

    $collect = value($thisTerm->partlist->list[0], $environment);
    $subgoal = value($thisTerm->partlist->list[1], $environment);
    $into = value($thisTerm->partlist->list[2], $environment);

    $collect = renameVariables($collect, $level, $thisTerm);
    $newGoal = new Term($subgoal->name, renameVariables($subgoal->partlist->list, $level, $thisTerm));
    $newGoal->parent = $thisTerm;

    $newGoals = array();
    $newGoals[0] = $newGoal;

    // Prove this subgoal, collecting up the environments...
    $anslist->list = array();
    $anslist->renumber = -1;
    $ret = prove($newGoals, $environment, $db, $level + 1, BagOfCollectFunction($collect, $anslist));

    // Turn anslist into a proper list and unify with 'into'
    // optional here: nil anslist -> fail?
    $answers = new Atom("nil");

    /*
      print("Debug: anslist = [");
      for (var j = 0; j < anslist.length; j++) {
      anslist[j].print();
      print(", ");
      }
      print("]\n");
     */

    for ($i = count($anslist->list); $i > 0; $i--)
        $answers = new Term("cons", array($anslist->list[$i - 1], $answers));

    //print("Debug: unifying "); into.print(); print(" with "); answers.print(); print("\n");
    $env2 = unify($into, $answers, $environment);

    if ($env2 == null) {
        //print("Debug: bagof cannot unify anslist with "); into.print(); print(", failing\n");
        return null;
    }

    // Just prove the rest of the goallist, recursively.
    return prove($goalList, $env2, $db, $level + 1, $reportFunction);
}

// Aux function: return the reportFunction to use with a bagof subgoal
function BagOfCollectFunction($collect, $anslist) {
    return function($env) use ($collect, $anslist) {
                /*
                  print("DEBUG: solution in bagof/3 found...\n");
                  print("Value of collection term ");
                  collect.print();
                  print(" in this environment = ");
                  (value(collect, env)).print();
                  print("\n");
                  printEnv(env);
                 */
                // Rename this appropriately and throw it into anslist
                $anslist->list[count($anslist->list)] = renameVariables(value($collect, $env), $anslist->renumber--, array());
            };
}

// Call out to external javascript
// external/3 takes three arguments:
// first: a template string that uses $1, $2, etc. as placeholders for

function External($thisTerm, $goalList, $environment, $db, $level, $reportFunction) {
    //print ("DEBUG: in External...\n");
    // Get the first term, the template.
    $first = value($thisTerm->partlist->list[0], $environment);
    if ($first->type != "Atom") {
        //print("Debug: External needs First bound to a string Atom, failing\n");
        return null;
    }

    if (!preg_match('#^"(.*)"$#', $first->name, $r))
        return null;
    $r = $r[1];

    //print("DEBUG: template for External/3 is "+r+"\n");
    // Get the second term, the argument list.
    $second = value($thisTerm->partlist->list[1], $environment);
    $arglist = array();
    $i = 1;
    while (($second->type == "Term") && ($second->name == "cons")) {
        // Go through second an argument at a time...
        $arg = value($second->partlist->list[0], $environment);
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
    $env2 = unify($thisTerm->partlist->list[2], new Atom($ret), $environment);

    if ($env2 == null) {
        //print("Debug: External/3 cannot unify OutValue with " + ret + ", failing\n");
        return null;
    }

    // Just prove the rest of the goallist, recursively.
    return prove($goalList, $env2, $db, $level + 1, $reportFunction);
}

class ReportStack {

    protected $stack = array();
    protected $success = false;

    public function log($which, $environment) {
        // Print bindings.
        if (count($which) == 0) {
            $this->success = true;
        } else {
            for ($i = 0; $i < count($which); $i++) {
                $obj = value(new Variable($which[$i]->name . ".0"), $environment);
                $this->stack[$which[$i]->name] = (string) $obj;
            }
        }
    }

    public function dump() {
        print_r($this->stack);
    }

    public function __get($name) {
        return $this->stack[$name];
    }

}