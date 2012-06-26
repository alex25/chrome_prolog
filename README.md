# InferenceBundle

## An inference engine for PHP

This is a library for a Prolog Interpreter. Planned for a Symfony2 Bundle.

-- CURRENTLY UNDER HEAVY REFACTORING --

Nevertheless : [![Build Status](https://secure.travis-ci.org/Trismegiste/InferenceBundle.png)](http://travis-ci.org/Trismegiste/InferenceBundle)

Prolog is an old language, frankly almost obsolete, and it has very limited use but it
can simplify some problems with few lines. Its secrets ? It embeds an inference engine with
forward chaining and unification.

Wikipedia says:

<blockquote><p>Prolog has its roots in first-order logic, a formal logic, and unlike many
other programming languages, Prolog is declarative: the program logic is
expressed in terms of relations, represented as facts and rules. A computation
is initiated by running a query over these relations.</p></blockquote>

For example, to implement some business intelligence algorithms, you can avoid
big boring sequences of if-else-switch or a big bunch of Chain of Responsability 
in PHP with a limited (and readable) set of rules and predicates in Prolog.

This is a port from a (dead) js version https://github.com/crcx/chrome_prolog
(kept by respect for its author)

Planning :

* classify all this bunch of functions : in progress
* refactor the model to be more PHP-like and not js-like : remove some weird 
objects and bizarre closures to protect access to internal methods.
* namespacing the classes
* using getters and setters (maybe not : perfs ?)
* create a builder to easily construct atom & term without parsing (really usefull ? )
* make a bundle for symfony2 because it is the most advance and mature framework for PHP