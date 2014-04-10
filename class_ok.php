<?php

/**
 * Buffer for class already correct
 */
class Atom
{

    public $name;
    public $type;

    public function __construct($head)
    {
        $this->name = $head;
        $this->type = "Atom";
    }

    public function __toString()
    {
        return (string) $this->name;
    }

}

class Variable
{

    public $name;
    public $type;

    public function __construct($head)
    {
        $this->name = $head;
        $this->type = "Variable";
    }

    public function __toString()
    {
        return $this->name;
    }

}

class Tokeniser
{

    public $remainder;
    public $current;

    // The Tiny-Prolog parser goes here.
    public function __construct($string)
    {
        $this->remainder = $string;
        $this->current = null;
        $this->type = null; // "eof", "id", "var", "punc" etc.
        $this->consume(); // Load up the first token.
    }

    public function consume()
    {
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

class Term
{

    public $name;
    public $type;
    public $partlist;
    public $cut = false;
    public $parent = null;

    public function __construct($head, $list)
    {
        $this->name = $head;
        $this->partlist = new Partlist($list);
        $this->type = "Term";
    }


    public function __toString()
    {
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

class Partlist
{

    public $list;

    public function __construct($list)
    {
        $this->list = $list;
    }

    public function __toString()
    {
        $retour = array();
        foreach ($this->list as $item)
            $retour[] = (string) $item;
        return implode(', ', $retour);
    }

}

class Rule
{

    public $head;
    public $body;

    public function __construct($head, $bodylist = null)
    {
        $this->head = $head;
        if ($bodylist != null)
            $this->body = new Body($bodylist);
        else
            $this->body = null;
    }

    public function __toString()
    {
        if ($this->body == null) {
            return ((string) $this->head) . ".";
        } else {
            return sprintf("%s :- %s.", $this->head, $this->body);
        }
    }

}

class Body
{

    public $list;

    public function __construct($list)
    {
        $this->list = $list;
    }

    public function __toString()
    {
        $retour = array();
        foreach ($this->list as $item)
            $retour[] = (string) $item;
        return implode(', ', $retour);
    }

}

class ReportStack
{

    protected $stack = array();
    protected $success = false;
    protected $interpreter = null;

    public function __construct(Interpreter $solv)
    {
        $this->interpreter = $solv;
    }

    public function log($which, $environment)
    {
        // Print bindings.
        if (count($which) == 0) {
            $this->success = true;
        } else {
            foreach ($which as $item) {
                $obj = $this->interpreter->value(new Variable($item->name . ".0"), $environment);
                $this->stack[$item->name][] = (string) $obj;
            }
        }
    }

    public function dump()
    {
        print_r($this->stack);
        if ($this->success)
            echo "\ntrue\n";
    }

    public function __get($name)
    {
        $val = $this->stack[$name];
        return (count($val) > 1) ? $val : $val[0];
    }

    public function isSuccess()
    {
        return $this->success;
    }

}