<?php

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

function execute($rules, $query) {

    $show = true;

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

    echo ("\nAttaching builtins to database.\n");
    $outr['builtin'] = array(
    /*    "compare/3" => Comparitor,
        "cut/0" => Cut,
        "call/1" => Call,
        "fail/0" => Fail,
        "bagof/3" => BagOf,
        "external/3" => External,
        "external2/3" => ExternalAndParse*/
    );

    echo ("Attachments done.\n");

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

    //renameVariables($q->list, 0, array());
    // Prove the query.
    prove(renameVariables($q->list, 0, array()), array(), $outr, 1, applyOne('printVars', $vs));
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
    return function ($arg2) {
                return $f($arg1, $arg2);
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
        $append;
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
/*    $builtin = $db['builtin'][$thisTerm->name . "/" . count($thisTerm->partlist->list)];
    // print ("Debug: searching for builtin "+thisTerm.name+"/"+thisTerm.partlist.list.length+"\n");
    if ($builtin) {
        //print ("builtin with name " + thisTerm.name + " found; calling prove() on it...\n");
        // Stick the new body list
        $newGoals = array();
        for ($j = 1; $j < count($goalList); $j++)
            $newGoals[$j - 1] = $goalList[$j];
        return $builtin($thisTerm, $newGoals, $environment, $db, $level + 1, $reportFunction);
    }
*/
    foreach ($db as $i => $item) {
        if ($i === 'builtin') continue;
        
        //print ("Debug: in rule selection. thisTerm = "); thisTerm.print(); print ("\n");
        if ($thisTerm->excludeRule === $i) {
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
        if ($env2 == null)
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
            $newGoals = aray();
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

$rules = <<<BOZ
#starwars
pere(anakin, luke).
mere(shmi, anakin).
mere(padme, anakin).

fils(X,Y) :- pere(Y,X).
fils(X,Y) :- mere(Y,X).
BOZ;
$query = "pere(anakin, X)";
//$query = "bagof(c, triple(sc, A, B), L), length(L, N) # L should have 21 elements";

execute($rules, $query)
?>
