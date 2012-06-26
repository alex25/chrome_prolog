This is a library for a Prolog Interpreter. Planned for a Symfony2 Bundle.

-- CURRENTLY UNDER HEAVY REFACTORING --

Prolog is an old language, almost obsolete, and it has very limited use but it
can simplify some problems with few lines. It embeds an inference engine with
forward chaining and unification.

Wikipedia says:
<<
Prolog has its roots in first-order logic, a formal logic, and unlike many
other programming languages, Prolog is declarative: the program logic is
expressed in terms of relations, represented as facts and rules. A computation
is initiated by running a query over these relations.
>>

For example, to implement some business intelligence algorithms, you can avoid
big boring sequences of if-else-switch in PHP with a limited set of rules
and predicates in Prolog.

[![Build Status](https://secure.travis-ci.org/Trismegiste/InferenceBundle.png)](http://travis-ci.org/Trismegiste/InferenceBundle)

This is a port from a js (dead) version https://github.com/crcx/chrome_prolog

Planning :
* classify all this bunch of functions : in progress
* namespacing the classes
* refactor the model to be more PHP-like and not js-like (remove some weird object)
* using getters and setters (maybe not : perfs ?)
* create a builder to easily construct atom & term without parsing (really usefull ? )
* make a bundle for symfony2 because it is the most advance and mature framework for PHP

