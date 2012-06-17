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
    /*
      echo ("\nAttaching builtins to database.\n");
      $outr['builtin'] = array(
      "compare/3" => Comparitor,
      "cut/0" => Cut,
      "call/1" => Call,
      "fail/0" => Fail,
      "bagof/3" => BagOf,
      "external/3" => External,
      "external2/3" => ExternalAndParse
      );

      echo ("Attachments done.\n");
     */
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

    $vs = varNames($q->list);

    // Prove the query.
    prove(renameVariables($q->list, 0, array()), array(), $outr, 1, applyOne('printVars', $vs));
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

$rules = <<<BOZ
#starwars
pere(anakin, luke).
mere(shmi, anakin).
mere(padme, anakin).

fils(X,Y) :- pere(Y,X).
fils(X,Y) :- mere(Y,X).
BOZ;
$query = "pere(anakin, X)";

execute($rules, $query)
?>
